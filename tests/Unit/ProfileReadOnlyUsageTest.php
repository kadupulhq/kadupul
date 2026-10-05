<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace ProfileReadOnlyUsageTest;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/data_source_profiles.php');
if (!is_string($source)) throw new \RuntimeException('Unable to read profile page');
eval('namespace ' . __NAMESPACE__ . '; use \\RuntimeException; use \\Throwable;' . test_php_function_source($source, 'profile_is_read_only'));
if (!defined('MESSAGE_LEVEL_WARN')) define('MESSAGE_LEVEL_WARN', 2);
function db_fetch_cell_prepared($sql, $parameters)
{
    $GLOBALS['profile_usage_queries'][] = [$sql, $parameters];
    if ($GLOBALS['profile_usage_result'] instanceof \Throwable) throw $GLOBALS['profile_usage_result'];
    return $GLOBALS['profile_usage_result'];
}
function cacti_log(...$arguments)
{
    $GLOBALS['profile_usage_logs'][] = $arguments;
}
function raise_message(...$arguments)
{
    $GLOBALS['profile_usage_messages'][] = $arguments;
}
function __($message)
{
    return $message;
}

test('profile usage admits unused definitions and warns on unavailable counts', function ($count, $readOnly, $warn) {
    $GLOBALS['profile_usage_result'] = $count;
    $GLOBALS['profile_usage_queries'] = $GLOBALS['profile_usage_logs'] = $GLOBALS['profile_usage_messages'] = [];
    expect(profile_is_read_only(7))->toBe($readOnly);
    expect($GLOBALS['profile_usage_queries'])->toHaveCount(1);
    expect($GLOBALS['profile_usage_queries'][0][1])->toBe([7]);
    expect($GLOBALS['profile_usage_logs'])->toHaveCount($warn ? 1 : 0);
    expect($GLOBALS['profile_usage_messages'])->toHaveCount($warn ? 1 : 0);
    if ($warn) expect($GLOBALS['profile_usage_messages'][0][0])->toBe('profile_usage_unavailable');
})->with([
    [0, false, false], ['0', false, false], [1, true, false], ['12', true, false],
    [false, true, true], [null, true, true], ['', true, true], ['unknown', true, true],
    ['1e0', true, true], ['-1', true, true], [1.5, true, true], [[], true, true],
    [new \RuntimeException('backend unavailable'), true, true],
]);
