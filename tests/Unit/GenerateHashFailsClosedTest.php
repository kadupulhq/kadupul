<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$functionsSource = file_get_contents(__DIR__ . '/../../lib/functions.php');
$start = strpos($functionsSource, 'function generate_hash()');
$end = strpos($functionsSource, "\n}", $start + 1);
$generateHash = substr($functionsSource, $start, $end - $start);

test('generate_hash uses cryptographic randomness without a weak fallback', function () use ($generateHash) {
    expect($generateHash)->toContain('bin2hex(random_bytes(16))');
    expect($generateHash)->not->toContain('catch');
    expect($generateHash)->not->toContain('md5(');
    expect($generateHash)->not->toContain('rand(');
});
