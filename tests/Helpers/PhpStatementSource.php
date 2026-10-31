<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Extract one complete assignment that contains the requested production call. */
function test_php_assignment_source(string $source, string $variable, string $callee, ?string $array_key = null): string
{
    $tokens = token_get_all($source);
    $significant = array();
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) $significant[] = $index;
    }
    $matches = array();
    foreach ($significant as $position => $index) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) continue;
        $next = $position + 1;
        if ($array_key !== null) {
            $open = $tokens[$significant[$next] ?? $index];
            $key = $tokens[$significant[$next + 1] ?? $index];
            $close = $tokens[$significant[$next + 2] ?? $index];
            if ($open !== '[' || $close !== ']' || !is_array($key) || $key[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
            // The lexer has proved this is a quoted literal, so quote style and escapes are immaterial.
            if (eval('return ' . $key[1] . ';') !== $array_key) continue;
            $next += 3;
        }
        if (($tokens[$significant[$next] ?? $index]) !== '=') continue;
        $statement = '';
        $depth = 0;
        $called = false;
        $complete = false;
        for ($cursor = $index; $cursor < count($tokens); $cursor++) {
            $part = $tokens[$cursor];
            $statement .= is_array($part) ? $part[1] : $part;
            if (is_array($part) && in_array($part[0], array(T_STRING, T_NAME_FULLY_QUALIFIED), true) && strcasecmp(ltrim($part[1], '\\'), $callee) === 0) {
                $after = $cursor + 1;
                while (isset($tokens[$after]) && is_array($tokens[$after]) && in_array($tokens[$after][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) $after++;
                $called = $called || (($tokens[$after] ?? null) === '(');
            }
            if (in_array($part, array('(', '[', '{'), true) || (is_array($part) && in_array($part[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
                $depth++;
            } elseif (in_array($part, array(')', ']', '}'), true)) {
                $depth--;
            } elseif ($part === ';' && $depth === 0) {
                $complete = true;
                break;
            }
        }
        if ($called && !$complete) throw new RuntimeException('Assignment has no terminating semicolon');
        if ($called) $matches[] = $statement;
    }
    if (count($matches) !== 1) throw new RuntimeException('Expected one assignment for ' . $variable . ' calling ' . $callee);
    return $matches[0];
}
