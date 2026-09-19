<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression test for sanitize_uri() drop-list hardening in lib/functions.php.
 *
 * Fix: appended backslash, NUL (\0), carriage return (\r), and newline (\n)
 * to the drop-char list. These characters could be used for header injection
 * and path traversal across all callers of sanitize_uri().
 */

require_once dirname(__DIR__, 4) . '/lib/functions.php';

test('sanitize_uri removes dangerous characters from literal and encoded requests', function ($character) {
    expect(sanitize_uri('/index.php?x=a' . $character . 'b'))->toBe('/index.php?x=ab')
        ->and(sanitize_uri('/index.php?x=a' . rawurlencode($character) . 'b'))->toBe('/index.php?x=ab');
})->with(array(chr(92), chr(0), chr(13), chr(10), ';', '{', '}', '^', '$'));

test('sanitize_uri preserves IPv6 brackets only when explicitly requested', function () {
    expect(sanitize_uri('http://[::1]/index.php', true))->toBe('http://[::1]/index.php')
        ->and(sanitize_uri('/index.php?x=[a]'))->toBe('/index.php?x=a');
});
