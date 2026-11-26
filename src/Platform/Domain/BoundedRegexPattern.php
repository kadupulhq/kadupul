<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain;

/**
 * Compiles and executes user-supplied log/search regexes with a consistent
 * escaped delimiter and strict PCRE backtrack, depth, match, and heap ceilings
 * to prevent catastrophic backtracking (ReDoS).
 */
final readonly class BoundedRegexPattern
{
    public const int MAX_LENGTH = 512;
    private const string DELIMITER = "\x01";
    private const string LIMIT_PREFIX = '(*NO_JIT)(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=256)(*LIMIT_HEAP=1024)';

    public static function compile(string $pattern, string $modifiers = 'i', int $maxLength = self::MAX_LENGTH): ?string
    {
        if ($pattern === '' || strlen($pattern) > $maxLength || str_contains($pattern, "\0")) {
            return null;
        }

        if (preg_match('/\A[imsuxADU]*\z/D', $modifiers) !== 1) {
            return null;
        }

        // Reject user-supplied PCRE verb overrides that would raise limits or enable callouts.
        if (preg_match('/\(\*(?:LIMIT_|NO_AUTO_POSSESS|NO_START_OPT|UTF|UCP|BSR_|CR|LF|CRLF|ANY|MARK|PRUNE|SKIP|THEN|COMMIT|ACCEPT|FAIL|\?C)/i', $pattern) === 1
            || str_contains($pattern, '(?C')) {
            return null;
        }

        $escaped = self::escapeDelimiter($pattern);
        $compiled = self::DELIMITER . self::LIMIT_PREFIX . $escaped . self::DELIMITER . $modifiers;

        $oldBacktrack = ini_get('pcre.backtrack_limit');
        $oldRecursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', '10000');
        ini_set('pcre.recursion_limit', '256');

        try {
            $check = @preg_match($compiled, '');
        } finally {
            if ($oldBacktrack !== false) {
                ini_set('pcre.backtrack_limit', $oldBacktrack);
            }
            if ($oldRecursion !== false) {
                ini_set('pcre.recursion_limit', $oldRecursion);
            }
        }

        return $check === false ? null : $compiled;
    }

    public static function matches(string $compiled, string $subject): bool
    {
        $oldBacktrack = ini_get('pcre.backtrack_limit');
        $oldRecursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', '10000');
        ini_set('pcre.recursion_limit', '256');

        try {
            return @preg_match($compiled, $subject) === 1;
        } finally {
            if ($oldBacktrack !== false) {
                ini_set('pcre.backtrack_limit', $oldBacktrack);
            }
            if ($oldRecursion !== false) {
                ini_set('pcre.recursion_limit', $oldRecursion);
            }
        }
    }

    private static function escapeDelimiter(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);
        $escaped = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($escaped) {
                $out .= $ch;
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $out .= $ch;
                $escaped = true;
                continue;
            }
            if ($ch === self::DELIMITER) {
                $out .= '\\' . $ch;
                continue;
            }
            $out .= $ch;
        }

        return $out;
    }
}
