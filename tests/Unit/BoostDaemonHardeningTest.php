<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$boostSource  = file_get_contents(__DIR__ . '/../../lib/boost.php');
$pollerSource = file_get_contents(__DIR__ . '/../../lib/poller.php');
$funcSource   = file_get_contents(__DIR__ . '/../../lib/functions.php');

// Mode 'x' refuses an existing name or link, the image is written through the
// descriptor, and rename() replaces the cache name without following it.
test('boost_graph_set_file writes a temporary file in the cache directory and renames it into place', function () use ($boostSource) {
    $start = strpos($boostSource, 'function boost_graph_set_file(');
    $body = substr($boostSource, $start, strpos($boostSource, "\nfunction ", $start) - $start);
    expect($body)->toContain("\$temp_file = \$cache_directory . '/' . BOOST_PNG_TEMP_PREFIX")
        ->and($body)->toContain("fopen(\$temp_file, 'xb')")
        ->and($body)->toContain('fwrite($handle, $output)')
        ->and($body)->toContain('rename($temp_file, $cache_file)')
        ->and($body)->not->toContain('file_put_contents($temp_file')
        ->and($body)->not->toContain('chmod(')
        ->and($body)->not->toContain('tempnam(')
        ->and($body)->not->toContain('umask(')
        ->and($body)->not->toContain('chmod($cache_file')
        ->and($body)->not->toContain('fopen($cache_file');
});

test('boost_graph_cache_check casts IDs to int', function () use ($boostSource) {
    $start = strpos($boostSource, 'function boost_graph_cache_check(');
    $body = substr($boostSource, $start, 500);
    expect($body)->toContain('$local_graph_id = (int) $local_graph_id');
    expect($body)->toContain('$rra_id         = (int) $rra_id');
});

test('boost_graph_set_file casts IDs to int', function () use ($boostSource) {
    $start = strpos($boostSource, 'function boost_graph_set_file(');
    $body = substr($boostSource, $start, 1500);
    expect($body)->toContain('(int) $local_graph_id');
    expect($body)->toContain('(int) $rra_id');
});

test('boost GET_LOCK has retry limit', function () use ($boostSource) {
    expect($boostSource)->toContain('$max_attempts');
    expect($boostSource)->toContain('if (++$lock_attempts >= $max_attempts)');
});

test('exec_with_timeout does not use exec setsid', function () use ($pollerSource) {
    $start = strpos($pollerSource, 'function exec_with_timeout(');
    $body = substr($pollerSource, $start, 1000);
    $tokens = token_get_all('<?php ' . $body);
    $code = implode('', array_map(static fn($token) => is_array($token) ? (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true) ? '' : $token[1]) : $token, $tokens));
    expect($code)->not->toContain('exec setsid');
    expect($body)->toContain('proc_open($cmd,');
});

test('cacti_unserialize prevents class restoration on supported PHP', function () use ($funcSource) {
    $source = test_php_function_source($funcSource, 'cacti_unserialize');
    $source = str_replace('function cacti_unserialize(', 'function boost_hardening_unserialize(', $source);
    eval($source);
    $value = boost_hardening_unserialize(serialize((object) array('value' => 7)));
    expect($value)->toBeInstanceOf(__PHP_Incomplete_Class::class)
        ->and($value)->not->toBeInstanceOf(stdClass::class)
        ->and(boost_hardening_unserialize(serialize(array('value' => 7))))->toBe(array('value' => 7));
});

test('cacti_unserialize rejects null and empty input', function () use ($funcSource) {
    $start = strpos($funcSource, 'function cacti_unserialize(');
    $body = substr($funcSource, $start, 300);
    expect($body)->toContain("\$strobj === null || \$strobj === ''");
});

test('legacy writer lock retries stop at the configured bound and admit a successful acquisition', function () use ($boostSource) {
    $source = test_php_function_source($boostSource, 'boost_acquire_legacy_lock');
    eval('namespace BoostLockProbe; function usleep($delay) {} function db_fetch_cell_prepared($sql, $params) { $GLOBALS["boostLockCalls"][] = array($sql, $params); return array_shift($GLOBALS["boostLockResults"]); } ' . $source);
    $GLOBALS['boostLockCalls'] = array();
    $GLOBALS['boostLockResults'] = array(false, false, true);
    expect(\BoostLockProbe\boost_acquire_legacy_lock(42, 2))->toBeFalse()
        ->and(count($GLOBALS['boostLockCalls']))->toBe(2)
        ->and($GLOBALS['boostLockCalls'][0])->toBe(array('SELECT GET_LOCK(?, 1)', array('boost.single_ds.42')));
    $GLOBALS['boostLockCalls'] = array();
    $GLOBALS['boostLockResults'] = array(false, true);
    expect(\BoostLockProbe\boost_acquire_legacy_lock(42, 2))->toBeTrue()
        ->and(count($GLOBALS['boostLockCalls']))->toBe(2);
});
