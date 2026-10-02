<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * appendHeaderSuppression() guarded "already present?" with
 * strpos($url, 'header=false') < 0. strpos() returns false on no-match
 * and a non-negative integer on a hit; "< 0" is therefore always false
 * because false < 0 is also false. The guard never fired and the function
 * appended &header=false on every call, eventually producing URLs like
 *   ?action=edit&header=false&header=false&header=false
 * The guard uses an explicit substring-presence check.
 */

$source = file_get_contents(__DIR__ . '/../../lib/functions.php');

test('appendHeaderSuppression checks whether the flag is absent', function () use ($source) {
    $start = strpos($source, 'function appendHeaderSuppression(');
    expect($start)->not->toBeFalse();

    $end  = strpos($source, "\nfunction ", $start + 1);
    $body = substr($source, $start, $end !== false ? $end - $start : 400);

    expect($body)->toContain("!str_contains(\$url, 'header=false')");
    expect(strpos($body, "strpos(\$url, 'header=false') < 0"))
        ->toBeFalse('the old "< 0" guard must be gone');
});

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

// Execute the production definition without bootstrapping the application.
if (!function_exists('_test_appendHeaderSuppression')) {
    eval(str_replace(
        'function appendHeaderSuppression(',
        'function _test_appendHeaderSuppression(',
        test_php_function_source($source, 'appendHeaderSuppression')
    ));
}

test('appendHeaderSuppression is idempotent on repeated calls', function () {
    $first  = _test_appendHeaderSuppression('graph.php?action=edit');
    $second = _test_appendHeaderSuppression($first);

    expect($first)->toBe('graph.php?action=edit&header=false');
    expect($second)->toBe($first);

    /* Already present and no querystring delimiter swap. */
    expect(_test_appendHeaderSuppression('graph.php?header=false'))->toBe('graph.php?header=false');
});


test('appendHeaderSuppression retains a query delimiter at byte zero', function () {
    expect(_test_appendHeaderSuppression('?action=edit'))->toBe('?action=edit&header=false');
});
