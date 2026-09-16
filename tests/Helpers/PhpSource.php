<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

/** Extract a complete named function without counting braces in comments or strings. */
function test_php_function_source(string $source, string $name): string
{
    $tokens = token_get_all($source);
    foreach ($tokens as $start => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $cursor = $start + 1;
        while (isset($tokens[$cursor])) {
            $candidate = $tokens[$cursor];
            if (is_array($candidate) && in_array($candidate[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $cursor++;
                continue;
            }
            if ($candidate === '&' || (is_array($candidate) && $candidate[1] === '&')) {
                $cursor++;
                continue;
            }
            break;
        }
        if (!isset($tokens[$cursor]) || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_STRING || $tokens[$cursor][1] !== $name) {
            continue;
        }
        $result = '';
        $depth = 0;
        $opened = false;
        for ($i = $start; $i < count($tokens); $i++) {
            $part = $tokens[$i];
            $result .= is_array($part) ? $part[1] : $part;
            if ($part === '{' || (is_array($part) && in_array($part[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
                $depth++;
                $opened = true;
            } elseif ($part === '}' && --$depth === 0 && $opened) {
                return $result;
            } elseif ($part === ';' && !$opened) {
                break;
            }
        }
        throw new RuntimeException("Function has no complete body: $name");
    }
    throw new RuntimeException("Function not found: $name");
}
