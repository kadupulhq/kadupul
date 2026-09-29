<?php
/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$functionsSource = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
    define('POLLER_VERBOSITY_MEDIUM', 2);
}

function _prepare_validate_result_test_log($message, $data = null, $level = 0)
{
}

function _prepare_validate_result_test_is_hexadecimal($result)
{
    return false;
}

function _prepare_validate_result_test_strip_alpha($result)
{
    return false;
}

preg_match('/^function prepare_validate_result\([^)]*\)\s*\{.*?^\}/sm', $functionsSource, $match);
if (!isset($match[0])) {
    throw new RuntimeException('prepare_validate_result() definition not found');
}

$validatorSource = preg_replace(
    '/^function prepare_validate_result\(/m',
    'function _prepare_validate_result_multi_delimiter(',
    $match[0]
);
$validatorSource = str_replace(
    array('dsv_log(', 'is_hexadecimal(', 'strip_alpha('),
    array('_prepare_validate_result_test_log(', '_prepare_validate_result_test_is_hexadecimal(', '_prepare_validate_result_test_strip_alpha('),
    $validatorSource
);
eval($validatorSource); // nosemgrep: php.lang.security.eval-use.eval-use

test('prepare_validate_result normalizes bang-separated multi-value fields', function () {
    $result = 'users!14 load!0.42';

    expect(_prepare_validate_result_multi_delimiter($result))->toBeTrue();
    expect($result)->toBe('users:14 load:0.42');
});

test('prepare_validate_result accepts mixed multi-value delimiters consistently', function () {
    $result = 'users:14 load!0.42';

    expect(_prepare_validate_result_multi_delimiter($result))->toBeTrue();
    expect($result)->toBe('users:14 load:0.42');
});
