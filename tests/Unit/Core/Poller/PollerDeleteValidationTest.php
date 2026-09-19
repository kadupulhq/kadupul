<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerDeleteValidation;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'poller_delete_output_rows'));
function cacti_sizeof($value)
{
    return count($value);
}
function db_execute_prepared($sql, $params)
{
    $GLOBALS['delete_validation_calls'][] = $params;
    return true;
}
function db_affected_rows()
{
    return count(end($GLOBALS['delete_validation_calls'])) / 4;
}

beforeEach(function () {
    $GLOBALS['delete_validation_calls'] = array();
});

test('malformed later chunks cannot delete earlier valid samples', function ($invalid) {
    $keys = array_fill(0, 500, array(1, 'value', '2026-01-01', '42'));
    $keys[] = $invalid;
    $failed = false;
    expect(poller_delete_output_rows($keys, $failed))->toBe(0)
        ->and($failed)->toBeTrue()
        ->and($GLOBALS['delete_validation_calls'])->toBe(array());
})->with(array(
    'short' => array(array(1, 'value', '2026-01-01')),
    'scalar' => array('invalid'),
    'sparse' => array(array(0 => 1, 1 => 'value', 2 => '2026-01-01', 4 => '42')),
    'extra' => array(array(1, 'value', '2026-01-01', '42', 'extra')),
));

test('valid multi-chunk selections preserve their exact values', function () {
    $key = array(1, 'value', '2026-01-01', '42 ');
    $failed = true;
    expect(poller_delete_output_rows(array_fill(0, 501, $key), $failed))->toBe(501)
        ->and($failed)->toBeFalse()
        ->and(count($GLOBALS['delete_validation_calls']))->toBe(2)
        ->and($GLOBALS['delete_validation_calls'][1])->toBe($key);
});
