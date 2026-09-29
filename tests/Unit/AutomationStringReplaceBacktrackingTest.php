<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__, 2) . '/lib/api_automation.php';

test('tree automation keeps ordinary case-insensitive replacements', function () {
    expect(automation_string_replace('host-[0-9]+', 'Device', 'HOST-17'))
        ->toBe(array('Device'));
});

test('tree automation supports a delimiter present in the expression', function () {
    expect(automation_string_replace('sensor~[0-9]+', 'disk', 'sensor~3'))
        ->toBe(array('disk'));
});

test('tree automation can select a delimiter when common delimiters are present', function () {
    expect(automation_string_replace('^[~#%!@;`=/_]+$', 'matched', '~#%!@;`=/_'))
        ->toBe(array('matched'));
});

test('tree automation stops catastrophic near-match backtracking', function () {
    $started = microtime(true);
    $result = automation_string_replace('^(a+)+$', 'matched', str_repeat('a', 255) . '!');
    $elapsed = microtime(true) - $started;

    expect($result)->toBe(array());
    expect(preg_last_error())->toBe(PREG_BACKTRACK_LIMIT_ERROR);
    expect($elapsed)->toBeLessThan(1.0);
});
