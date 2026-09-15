<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace BoostHandoffRetryTest;

function cacti_log(...$args)
{
}
function cacti_sizeof($rows)
{
    return count($rows);
}
function db_fetch_assoc($sql)
{
    return array(array('local_data_id' => 7));
}
function db_fetch_assoc_prepared(...$args)
{
    return array();
}
function array_rekey($rows, ...$args)
{
    return $rows;
}
function boost_poller_on_demand(&$rows)
{
    return null;
}
function db_execute(...$args)
{
    throw new \RuntimeException('Source samples must survive a failed handoff');
}
function rrdtool_function_update(...$args)
{
    throw new \RuntimeException('A failed handoff must not write an RRD');
}

if (!defined('POLLER_VERBOSITY_HIGH')) {
    define('POLLER_VERBOSITY_HIGH', 5);
}
if (!defined('SQL_NO_CACHE')) {
    define('SQL_NO_CACHE', '');
}
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
if (!preg_match('/^function process_poller_output\(.*?^}\n/ms', $source, $match)) {
    throw new \RuntimeException('Missing production poller function');
}
eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]); // nosemgrep: php.lang.security.eval-use.eval-use

test('failed Boost handoff retains source samples and skips direct RRD writes', function () {
    $saved = $GLOBALS['config'] ?? null;
    $root = sys_get_temp_dir() . '/boost-retry-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    file_put_contents($root . '/rrd.php', '<?php');
    $GLOBALS['config']['library_path'] = $root;
    try {
        $pipe = null;
        $deferred = false;
        expect(process_poller_output($pipe, false, $deferred))->toBe(0)
            ->and($deferred)->toBeTrue();
    } finally {
        $GLOBALS['config'] = $saved;
        unlink($root . '/rrd.php');
        rmdir($root);
    }
});

function pollerDeferredProbe(&$pipe, $remainder, &$deferred)
{
    $GLOBALS['deferred_probe_calls']++;
    $deferred = true;
    return 0;
}

test('main poller skips subsequent drains and final drain after a deferred handoff', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    preg_match_all('/if \(\$poller_id == 1\) \{\s*if \(!\$poller_output_deferred\) \{.*?\n\t{5}\}/s', $source, $matches);
    expect($matches[0])->toHaveCount(2);
    $poller_id = 1;
    $poller_output_deferred = false;
    $rrds_processed = 0;
    $rrdtool_pipe = null;
    $GLOBALS['deferred_probe_calls'] = 0;
    // Execute the actual waiting-loop guard twice, then the completion guard.
    foreach (array($matches[0][1], $matches[0][1], $matches[0][0]) as $guard) {
        eval(str_replace('process_poller_output(', __NAMESPACE__ . '\\pollerDeferredProbe(', $guard)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
    expect($GLOBALS['deferred_probe_calls'])->toBe(1)
        ->and($poller_output_deferred)->toBeTrue();
});
