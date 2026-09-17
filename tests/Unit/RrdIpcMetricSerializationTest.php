<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$rrdSource = file_get_contents(__DIR__ . '/../../lib/rrd.php');

test('rrdtool_function_update trims string values before is_numeric', function () use ($rrdSource) {
    $body = test_php_function_source($rrdSource, 'rrdtool_function_update');
    expect($body)->toContain('trim($value)');
});

test('rrdtool_function_update rejects empty strings as U', function () use ($rrdSource) {
    $body = test_php_function_source($rrdSource, 'rrdtool_function_update');
    expect($body)->toContain("\$value === ''");
});

test('rrdtool_function_update uses locale-safe decimal replacement', function () use ($rrdSource) {
    $body = test_php_function_source($rrdSource, 'rrdtool_function_update');
    expect($body)->toContain("str_replace(',', '.', (string)\$value)");
});

test('rrdtool_function_update does not directly append raw value', function () use ($rrdSource) {
    $body = test_php_function_source($rrdSource, 'rrdtool_function_update');
    expect($body)->not->toContain('$rrd_update_values .= $value;');
});
