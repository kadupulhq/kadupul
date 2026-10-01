<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Runtime rejection and subprocess behavior are covered by WhitelistExecutionTest.

$source = file_get_contents(__DIR__ . '/../../data_input.php');

test('whitelist update is guarded before controller dispatch', function () use ($source) {
    $guard = preg_match("/cacti_require_post_actions\\(array\\([^)]*'whitelist_update'/", $source, $match, PREG_OFFSET_CAPTURE) ? $match[0][1] : false;
    $dispatch = strpos($source, "case 'whitelist_update':");
    expect($guard)->not->toBeFalse();
    expect($dispatch)->not->toBeFalse();
    expect($guard)->toBeLessThan($dispatch);
});

test('whitelist controller has no shell execution sink', function () use ($source) {
    expect($source)->not->toContain('shell_exec(');
    expect($source)->toContain('cacti_exec(');
});
