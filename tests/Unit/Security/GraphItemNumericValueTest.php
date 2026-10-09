<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Unit\Security;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

beforeAll(function (): void {
    // Load the immutable production body once per worker, not per dataset row.
    $source = file_get_contents(dirname(__DIR__, 3) . '/lib/rrd.php');
    \PHPUnit\Framework\Assert::assertIsString($source);
    eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source($source, 'rrdtool_graph_item_numeric_value'));
});

test('graph items accept exactly one numeric argument', function (string $value, ?string $expected): void {
    expect(rrdtool_graph_item_numeric_value($value))->toBe($expected);
})->with([
    'integer' => ['1', '1'],
    'negative integer' => ['-1', '-1'],
    'signed fraction' => ['+1.25', '+1.25'],
    'leading decimal point' => ['.5', '.5'],
    'trailing decimal point' => ['1.', '1.'],
    'exponent' => ['1e3', '1e3'],
    'negative exponent' => ['-1.5E-2', '-1.5E-2'],
    'empty sentinel' => ['', ''],
    'newline command' => ["1\nupdate /tmp/rrd.rrd N:1", null],
    'carriage return command' => ["1\rupdate /tmp/rrd.rrd N:1", null],
    'second argument' => ['1 update /tmp/rrd.rrd N:1', null],
    'colon separator' => ['1:2', null],
    'nonfinite value' => ['NaN', null],
]);
