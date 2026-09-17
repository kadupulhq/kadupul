<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace BoostHandoffRetryTest;

function read_config_option($key) {return 300;}
function cacti_log(...$args)
{
}
function cacti_sizeof($rows)
{
    return count($rows);
}
function db_fetch_assoc($sql)
{
    if (!empty($GLOBALS['diagnostic_probe'])) {
        if (str_contains($sql, 'WHERE dl.id IS NULL')) {
            $GLOBALS['diagnostic_orphan_queries']++;
            if ($GLOBALS['diagnostic_orphan_fail']) { return false; }
            $count = min(40000, $GLOBALS['diagnostic_orphan_remaining']);
            $GLOBALS['diagnostic_orphan_remaining'] -= $count;
            $orphans = array();
            for ($id = 1; $id <= $count; $id++) {
                $orphans[] = array('local_data_id' => $id + 100000 + $GLOBALS['diagnostic_orphan_remaining'], 'rrd_name' => 'value', 'time' => '2026-09-15 00:00:00', 'output' => '10');
            }
            return $orphans;
        }
        if (str_contains($sql, 'SELECT rrd_num')) {
            $GLOBALS['diagnostic_probe_ran'] = true;
            return array(array('name' => 'Partial', 'local_data_ids' => '7'));
        }
        if (!empty($GLOBALS['diagnostic_empty_selection'])) { return array(); }
        $GLOBALS['diagnostic_probe_reads']++;
        return $GLOBALS['cleanup_retry_rows'];
    }
    return $GLOBALS['cleanup_retry_rows'] ?? array(array('local_data_id' => 7));
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    if (str_contains($sql, 'AS incomplete')) {return array();}
    if (str_contains($sql, "FROM poller_output AS po")) {
        if (isset($GLOBALS["pagination_probe"])) {
            $GLOBALS["pagination_params"][] = $params;
            return array_shift($GLOBALS["pagination_probe"]);
        }
        return db_fetch_assoc($sql);
    }
    return array();
}
function array_rekey($rows, ...$args)
{
    return $rows;
}
function boost_poller_on_demand(&$rows)
{
    return isset($GLOBALS['cleanup_retry_rows']) ? true : null;
}
function db_execute(...$args)
{
    throw new \RuntimeException('Source samples must survive a failed handoff');
}
function rrdtool_function_update($updates, $pipe = false, &$completed = null)
{
    $completed = array();
    if (isset($GLOBALS['cleanup_retry_rows'])) {
        $GLOBALS['cleanup_retry_updates'] = $updates;
        if (!empty($GLOBALS['writer_failed'])) {return false;}
        foreach ($updates as $path => $fields) {
            foreach ($fields['times'] as $time => $values) {
                $completed[$path][$time] = true;
            }
        }
        return array_sum(array_map('count', $completed));
    }
    throw new \RuntimeException('A failed handoff must not write an RRD');
}

if (!defined('POLLER_VERBOSITY_HIGH')) {
    define('POLLER_VERBOSITY_HIGH', 5);
}
if (!defined('SQL_NO_CACHE')) {
    define('SQL_NO_CACHE', '');
}
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
foreach (array('poller_cleanup_orphan_rows', 'poller_expire_incomplete_rows', 'process_poller_output') as $name) {
    if (!preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match)) {
        throw new \RuntimeException('Missing production poller function');
    }
    eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]); // nosemgrep: php.lang.security.eval-use.eval-use
}

test('failed Boost handoff retains source samples and skips direct RRD writes', function () {
    $saved = $GLOBALS['config'] ?? null;
    $root = sys_get_temp_dir() . '/boost-retry-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    file_put_contents($root . '/rrd.php', '<?php');
    $GLOBALS['config']['library_path'] = $root;
    try {
        $pipe = null;
        $deferred = false;
        expect(process_poller_output($pipe, false, $deferred, $consumed))->toBe(0)
            ->and($consumed)->toBe(0)
            ->and($deferred)->toBeTrue();
    } finally {
        $GLOBALS['config'] = $saved;
        unlink($root . '/rrd.php');
        rmdir($root);
    }
});

function pollerDeferredProbe($remainder, &$deferred)
{
    $GLOBALS['deferred_probe_calls']++;
    $deferred = true;
    return 0;
}

test('main poller retries failed waiting drains and the final drain', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    preg_match_all('/if \(\$poller_id == 1\) \{\s*(?:if \(empty\(\$poller_output_deferred\)\) \{\s*)?\$rrds_processed \+= process_poller_output_batch\(.*?\n\t{5}\}/s', $source, $matches);
    expect($matches[0])->toHaveCount(2);
    $poller_id = 1;
    $poller_output_deferred = false;
    $rrds_processed = 0;
    $rrdtool_pipe = null;
    $GLOBALS['deferred_probe_calls'] = 0;
    // Execute the actual waiting-loop guard twice, then the completion guard.
    foreach (array($matches[0][1], $matches[0][1], $matches[0][0]) as $guard) {
        eval(str_replace('process_poller_output_batch(', '\\' . __NAMESPACE__ . '\\pollerDeferredProbe(', $guard)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
    expect($GLOBALS['deferred_probe_calls'])->toBe(3)
        ->and($poller_output_deferred)->toBeTrue()->and($rrd_write_failed)->toBeTrue();
});


function poller_delete_output_rows($keys, &$failed) {
    $GLOBALS['cleanup_retry_keys'] = $keys;
    if (!$keys) { $failed = false; return 0; }
    if (isset($GLOBALS['pagination_deleted'])) { $GLOBALS['pagination_deleted'] = array_merge($GLOBALS['pagination_deleted'], $keys); }
    if (!empty($GLOBALS['diagnostic_probe'])) {
        $failed = false;
        return count($keys);
    }
    $failed = true;
    return 1;
}
function dsstats_poller_output($rows) {}
function dsdebug_poller_output($rows) {}
function api_plugin_hook_function($name, $rows) {}
function db_fetch_cell($sql) {
    if (!empty($GLOBALS['diagnostic_count_fail']) && str_contains($sql, 'FROM poller_time')) {return false;}
    if (!empty($GLOBALS['writer_failed'])) { return 0; }
    if (!empty($GLOBALS['diagnostic_probe'])) { return str_contains($sql, 'FROM poller_time') ? 0 : 1; }
    throw new \RuntimeException('Cleanup failure must stop further drain queries');
}

test('write or cleanup failure defers remaining samples without premature deletion', function ($writer_failed) {
    $GLOBALS['writer_failed'] = $writer_failed;
    $saved = $GLOBALS['config'] ?? null;
    $root = sys_get_temp_dir() . '/cleanup-retry-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    file_put_contents($root . '/rrd.php', '<?php');
    $GLOBALS['config']['library_path'] = $root;
    $row = array('local_data_id' => 7, 'output' => '10', 'time' => '2026-09-15 00:00:00', 'unix_time' => 1789430400,
        'rrd_path' => '/example.rrd', 'rrd_name' => 'value', 'rrd_num' => 1, 'data_template_id' => 0);
    $next = $row;
    $next['time'] = '2026-09-15 00:01:00';
    $next['unix_time'] += 60;
    $next['output'] = '11';
    $GLOBALS['cleanup_retry_rows'] = array($row, $next);
    try {
        $pipe = null;
        expect(process_poller_output($pipe, false, $deferred, $consumed))->toBe($writer_failed ? 0 : 2)
            ->and($deferred)->toBeTrue()
            ->and($consumed)->toBe($writer_failed ? 0 : 1)
            ->and($GLOBALS['cleanup_retry_keys'] ?? array())->toBe($writer_failed ? array() : array(array(7, 'value', $row['time'], $row['output']), array(7, 'value', $next['time'], $next['output'])))
            ->and($GLOBALS['cleanup_retry_updates']['/example.rrd']['times'])->toBe(array($row['unix_time'] => array('value' => '10'), $next['unix_time'] => array('value' => '11')));
    } finally {
        unset($GLOBALS['writer_failed'], $GLOBALS['cleanup_retry_rows'], $GLOBALS['cleanup_retry_keys'], $GLOBALS['cleanup_retry_updates']);
        $GLOBALS['config'] = $saved;
        unlink($root . '/rrd.php');
        rmdir($root);
    }
})->with(array(false, true));


test('post-drain diagnostics preserve partial arrivals and fail closed on unreadable orphans', function ($lookup_fails, $orphan_count, $count_fails = false) {
    $GLOBALS['diagnostic_count_fail'] = $count_fails;
    $saved = $GLOBALS['config'] ?? null;
    $root = sys_get_temp_dir() . '/diagnostic-retry-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    file_put_contents($root . '/rrd.php', '<?php');
    $GLOBALS['config']['library_path'] = $root;
    $row = array('local_data_id' => 7, 'output' => '10', 'time' => '2026-09-15 00:00:00', 'unix_time' => 1789430400,
        'rrd_path' => '/example.rrd', 'rrd_name' => 'value', 'rrd_num' => 1, 'data_template_id' => 0);
    $partial = $row;
    $partial['time'] = '2026-09-15 00:01:00';
    $partial['unix_time'] += 60;
    $partial['rrd_num'] = 2;
    $GLOBALS['cleanup_retry_rows'] = array($row, $partial);
    $GLOBALS['diagnostic_probe'] = true;
    $GLOBALS['diagnostic_probe_reads'] = 0;
    $GLOBALS['diagnostic_probe_ran'] = false;
    $GLOBALS['diagnostic_orphan_fail'] = $lookup_fails;
    $GLOBALS['diagnostic_orphan_remaining'] = $orphan_count;
    $GLOBALS['diagnostic_orphan_queries'] = 0;
    try {
        $pipe = null;
        expect(process_poller_output($pipe, false, $deferred, $consumed))->toBe(1)
            ->and($deferred)->toBe($lookup_fails || $count_fails)
            ->and($consumed)->toBe(1 + (($lookup_fails || $count_fails) ? 0 : $orphan_count))
            ->and($GLOBALS['diagnostic_probe_ran'])->toBe(!$lookup_fails && !$count_fails)
            ->and($GLOBALS['diagnostic_orphan_queries'])->toBe($count_fails ? 0 : ($lookup_fails ? 1 : 2))
            ->and($GLOBALS['diagnostic_orphan_remaining'])->toBe(0);
        // db_execute() throws if either old broad diagnostic DELETE is reached.
    } finally {
        unset($GLOBALS['cleanup_retry_rows'], $GLOBALS['cleanup_retry_keys'], $GLOBALS['cleanup_retry_updates'],
            $GLOBALS['diagnostic_count_fail'], $GLOBALS['diagnostic_probe'], $GLOBALS['diagnostic_probe_reads'], $GLOBALS['diagnostic_probe_ran'], $GLOBALS['diagnostic_orphan_fail'], $GLOBALS['diagnostic_orphan_remaining'], $GLOBALS['diagnostic_orphan_queries']);
        $GLOBALS['config'] = $saved;
        unlink($root . '/rrd.php');
        rmdir($root);
    }
})->with(array(array(true, 0), array(false, 40003), array(false, 0, true)));


test('an orphan-only queue is drained or explicitly deferred on lookup failure', function ($lookup_fails) {
    $saved = $GLOBALS['config'] ?? null;
    $root = sys_get_temp_dir() . '/orphan-only-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    file_put_contents($root . '/rrd.php', '<?php');
    $GLOBALS['config']['library_path'] = $root;
    $GLOBALS['diagnostic_probe'] = true;
    $GLOBALS['diagnostic_empty_selection'] = true;
    $GLOBALS['diagnostic_orphan_fail'] = $lookup_fails;
    $GLOBALS['diagnostic_orphan_remaining'] = 3;
    $GLOBALS['diagnostic_orphan_queries'] = 0;
    try {
        $pipe = null;
        expect(process_poller_output($pipe, false, $deferred, $consumed))->toBe(0)
            ->and($deferred)->toBe($lookup_fails)
            ->and($consumed)->toBe($lookup_fails ? 0 : 3)
            ->and($GLOBALS['diagnostic_orphan_remaining'])->toBe($lookup_fails ? 3 : 0);
    } finally {
        unset($GLOBALS['diagnostic_probe'], $GLOBALS['diagnostic_empty_selection'], $GLOBALS['diagnostic_orphan_fail'],
            $GLOBALS['diagnostic_orphan_remaining'], $GLOBALS['diagnostic_orphan_queries'], $GLOBALS['cleanup_retry_keys']);
        $GLOBALS['config'] = $saved;
        unlink($root . '/rrd.php');
        rmdir($root);
    }
})->with(array(false, true));


test('an incomplete-only batch still diagnoses and cleans orphans without recursive draining', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
    preg_match('/^function process_poller_output\(.*?^}\n/ms', $source, $match);
    // A fresh function instance isolates the production one-shot warning flag.
    eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('process_poller_output(', 'partial_only_poller_output(', $match[0])); // nosemgrep: php.lang.security.eval-use.eval-use
    $saved = $GLOBALS['config'] ?? null;
    $root = sys_get_temp_dir() . '/partial-only-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    file_put_contents($root . '/rrd.php', '<?php');
    $GLOBALS['config']['library_path'] = $root;
    $row = array('local_data_id' => 7, 'output' => '10', 'time' => '2026-09-15 00:00:00', 'unix_time' => 1789430400,
        'rrd_path' => '/example.rrd', 'rrd_name' => 'value', 'rrd_num' => 2, 'data_template_id' => 0);
    $GLOBALS['cleanup_retry_rows'] = array($row, $row);
    $GLOBALS['diagnostic_probe'] = true;
    $GLOBALS['diagnostic_probe_reads'] = 0;
    $GLOBALS['diagnostic_probe_ran'] = false;
    $GLOBALS['diagnostic_orphan_fail'] = false;
    $GLOBALS['diagnostic_orphan_remaining'] = 3;
    $GLOBALS['diagnostic_orphan_queries'] = 0;
    try {
        $pipe = null;
        // Also exercise the main poller's final-drain argument (true, not max_rows).
        expect(partial_only_poller_output($pipe, true, $deferred, $consumed))->toBe(0)
            ->and($deferred)->toBeFalse()
            ->and($consumed)->toBe(3)
            ->and($GLOBALS['diagnostic_probe_ran'])->toBeTrue()
            ->and($GLOBALS['diagnostic_probe_reads'])->toBe(1);
        $GLOBALS['diagnostic_probe_ran'] = false;
        $GLOBALS['diagnostic_orphan_remaining'] = 2;
        expect(partial_only_poller_output($pipe, false, $deferred, $consumed))->toBe(0)
            ->and($consumed)->toBe(2)
            ->and($GLOBALS['diagnostic_probe_ran'])->toBeFalse()
            ->and($GLOBALS['diagnostic_probe_reads'])->toBe(2);
    } finally {
        unset($GLOBALS['cleanup_retry_rows'], $GLOBALS['cleanup_retry_keys'], $GLOBALS['cleanup_retry_updates'],
            $GLOBALS['diagnostic_count_fail'], $GLOBALS['diagnostic_probe'], $GLOBALS['diagnostic_probe_reads'], $GLOBALS['diagnostic_probe_ran'],
            $GLOBALS['diagnostic_orphan_fail'], $GLOBALS['diagnostic_orphan_remaining'], $GLOBALS['diagnostic_orphan_queries']);
        $GLOBALS['config'] = $saved;
        unlink($root . '/rrd.php');
        rmdir($root);
    }
});


test('keyset draining reaches complete samples behind an incomplete page and completes boundary groups', function ($complete_boundary) {
    $saved = $GLOBALS['config'] ?? null;
    $root = sys_get_temp_dir() . '/pagination-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    file_put_contents($root . '/rrd.php', '<?php');
    $GLOBALS['config']['library_path'] = $root;
    $row = array('local_data_id' => 1, 'output' => '10', 'time' => '2026-09-15 00:00:00', 'unix_time' => 1789430400,
        'rrd_path' => '/1.rrd', 'rrd_name' => 'a', 'rrd_num' => 2, 'data_template_id' => 0);
    $page = array();
    for ($id = 1; $id <= 40000; $id++) {
        $item = $row;
        $item['local_data_id'] = $id;
        $item['rrd_path'] = '/' . $id . '.rrd';
        $page[] = $item;
    }
    $tail = $page[39999];
    $tail['rrd_name'] = 'b';
    $later = $row;
    $later['local_data_id'] = 40001;
    $later['rrd_path'] = '/40001.rrd';
    $later['rrd_num'] = 1;
    $GLOBALS['pagination_probe'] = array($page, $complete_boundary ? array($tail) : array(), array($later));
    $GLOBALS['pagination_params'] = array();
    $GLOBALS['pagination_deleted'] = array();
    $GLOBALS['cleanup_retry_rows'] = array();
    $GLOBALS['diagnostic_probe'] = true;
    $GLOBALS['diagnostic_orphan_fail'] = false;
    $GLOBALS['diagnostic_orphan_remaining'] = 0;
    $GLOBALS['diagnostic_orphan_queries'] = 0;
    try {
        $pipe = null;
        expect(process_poller_output($pipe, false, $deferred, $consumed))->toBe($complete_boundary ? 2 : 1)
            ->and($deferred)->toBeFalse()
            ->and($consumed)->toBe($complete_boundary ? 3 : 1)
            ->and($GLOBALS['pagination_probe'])->toBe(array())
            ->and($GLOBALS['pagination_params'])->toBe(array(array(), array(40000, $row['time'], 'a'), array(40000, $row['time'])))
            ->and($GLOBALS['pagination_deleted'])->toHaveCount($complete_boundary ? 3 : 1)
            ->and($GLOBALS['pagination_deleted'])->toContain(array(40001, 'a', $row['time'], $row['output']));
    } finally {
        unset($GLOBALS['pagination_deleted'], $GLOBALS['pagination_probe'], $GLOBALS['pagination_params'], $GLOBALS['cleanup_retry_rows'],
            $GLOBALS['cleanup_retry_keys'], $GLOBALS['cleanup_retry_updates'], $GLOBALS['diagnostic_probe'],
            $GLOBALS['diagnostic_orphan_fail'], $GLOBALS['diagnostic_orphan_remaining'], $GLOBALS['diagnostic_orphan_queries']);
        $GLOBALS['config'] = $saved;
        unlink($root . '/rrd.php');
        rmdir($root);
    }
})->with(array(false, true));
