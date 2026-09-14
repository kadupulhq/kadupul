<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Installer::getModules() cached its result in $this->extensions but the
 * guard read
 *   if (isset($this->extensions) || empty($this->extensions))
 * which is always true on the first call (isset is false, empty is true)
 * and always true on every subsequent call (isset is now true). The cache
 * never paid off; utility_php_extensions() ran on every request. The fix
 * inverts the isset() check so the cache is only rebuilt when the value
 * has not been computed yet or is empty.
 */

$source = file_get_contents(__DIR__ . '/../../lib/installer.php');

if ($source === false) {
    throw new RuntimeException('Unable to read lib/installer.php');
}

require_once __DIR__ . '/../../lib/installer.php';

/*
 * lib/utility.php holds the real function, and no unit test loads it into
 * this process, so the stub below is the only definition. It counts calls so
 * the test can tell a cached result from a recomputed one.
 */
$GLOBALS['installer_extensions_cache_calls'] = 0;

function installer_extensions_cache_fixture()
{
    return [
        'mbstring' => ['cli' => true, 'web' => true, 'installed' => true],
        'xml'      => ['cli' => true, 'web' => true, 'installed' => true],
    ];
}

if (!function_exists('utility_php_extensions')) {
    function utility_php_extensions()
    {
        $GLOBALS['installer_extensions_cache_calls']++;

        return installer_extensions_cache_fixture();
    }
}

test('getModules computes the extension list once and serves the cache after', function () {
    $GLOBALS['installer_extensions_cache_calls'] = 0;

    $installer  = (new ReflectionClass(Installer::class))->newInstanceWithoutConstructor();
    $getModules = new ReflectionMethod(Installer::class, 'getModules');
    if (PHP_VERSION_ID < 80100) {
        $getModules->setAccessible(true);
    }

    $first  = $getModules->invoke($installer);
    $second = $getModules->invoke($installer);

    expect($first)->toBe(installer_extensions_cache_fixture())
        ->and($second)->toBe(installer_extensions_cache_fixture())
        ->and($GLOBALS['installer_extensions_cache_calls'])->toBe(1);
});

/*
 * Formatting guards. They read the source rather than run it, so they only
 * catch the guard being rewritten back to its buggy form; the runtime test
 * above is the behavior check.
 *
 * The method ends at the brace that matches its opening one. Walking the
 * tokens finds it whatever the indentation, and braces inside strings or
 * comments are not tokens of their own, so they cannot end the body early.
 */
$tokens = token_get_all($source);
$body   = '';

foreach ($tokens as $index => $token) {
    if (!is_array($token) || $token[0] !== T_FUNCTION) {
        continue;
    }

    $name = $index + 1;
    while (isset($tokens[$name]) && is_array($tokens[$name]) && $tokens[$name][0] === T_WHITESPACE) {
        $name++;
    }

    if (!isset($tokens[$name]) || !is_array($tokens[$name]) || $tokens[$name][1] !== 'getModules') {
        continue;
    }

    $depth = 0;
    for ($i = $index; $i < count($tokens); $i++) {
        $text  = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        $body .= $text;

        if ($text === '{' || (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($text === '}' && --$depth === 0) {
            break;
        }
    }

    break;
}

test('formatting guard: getModules body is bounded by its own braces', function () use ($body) {
    expect($body)->toStartWith('function getModules(')
        ->and($body)->toEndWith('}')
        ->and($body)->toContain('return $this->extensions;');
});

test('formatting guard: getModules guard begins with !isset', function () use ($body) {
    expect($body)->toContain('!isset($this->extensions) || empty($this->extensions)');
});

test('formatting guard: getModules no longer contains the original always-true guard', function () use ($body) {
    /* The buggy form started "if (isset(...". The fixed form starts
     * "if (!isset(...", so this substring cannot match the fix and is a
     * clean negative check for the regression. */
    expect($body)->not->toContain('if (isset($this->extensions) || empty($this->extensions))');
});
