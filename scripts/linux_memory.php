<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Ported from scripts/linux_memory.pl, which ran `cat /proc/meminfo | grep -w`.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** A pattern of word characters, '.' and ':' as a regex, where '.' matches any character in both grep and Perl. */
function linux_memory_pattern(string $text): string
{
    return implode('', array_map(static fn(string $character): string => $character === '.' ? '.' : preg_quote($character, '/'), str_split($text)));
}

/**
 * Prints the kB value of every meminfo line `grep -w` selected, back to back,
 * as linux_memory.pl did. Its value match used the raw argument, which differs
 * from the allow-listed one only by a trailing newline.
 */
function linux_memory_values(string $argument, string|false $meminfo): string
{
    if ($meminfo === false || $argument === '' || preg_match('/^([\w.:]+)$/', $argument, $match) !== 1) {
        return '';
    }

    $word = '/(?<![A-Za-z0-9_])' . linux_memory_pattern($match[1]) . '(?![A-Za-z0-9_])/';
    $value = '/(' . linux_memory_pattern($argument) . '.*\s)(.*[0-9])( kB)/';
    $output = '';
    $lines = explode("\n", $meminfo);
    foreach ($lines as $index => $line) {
        // Perl read each line with its newline; only an unterminated last line lacks one.
        $line .= $index < count($lines) - 1 ? "\n" : '';
        if (preg_match($word, $line) === 1 && preg_match($value, $line, $found) === 1) {
            $output .= $found[2];
        }
    }

    return $output;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $meminfo = is_readable('/proc/meminfo') ? file_get_contents('/proc/meminfo') : false;
    print linux_memory_values((string) ($_SERVER['argv'][1] ?? ''), $meminfo);
}
