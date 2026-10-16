<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

eval('namespace KadupulPadleftTests; ' . test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php'), 'padleft'));

// A regression ends the call by the CPU limit instead of blocking the run.
beforeEach(function () {
    set_time_limit(5);
});

afterEach(function () {
    set_time_limit(0);
});

test('padleft pads to the minimum length with any non-empty pad', function ($pad, $value, $min, $expected) {
    expect(\KadupulPadleftTests\padleft($pad, $value, $min))->toBe($expected);
})->with([
    'letter pad'              => ['x', 'ab', 5, 'xxxab'],
    'zero pad on zero'        => ['0', '0', 5, '00000'],
    'numeric value and pad'   => ['0', '5', 2, '05'],
    'decimal value'           => ['0', '5.5', 5, '005.5'],
    'multi character pad'     => ['ab', '5', 4, 'abab5'],
    'empty value'             => ['0', '', 2, '00'],
    'integer pad'             => [0, '5', 2, '05'],
    'multibyte pad'           => ["\xc3\xa9", '5', 3, "\xc3\xa95"],
    'already long enough'     => ['0', '123', 2, '123'],
    'empty pad is skipped'    => ['', '5', 2, '5'],
    'null pad is skipped'     => [null, '5', 2, '5'],
    'no minimum'              => ['0', '5', 0, '5'],
    'negative minimum'        => ['0', '5', -3, '5'],
]);
