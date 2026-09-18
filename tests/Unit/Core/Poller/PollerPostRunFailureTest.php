<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('RRD write failure preserves poller post-run services and only changes exit status', function ($pollerId, $recovery, $failed) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    $start = strpos($source, '// Finish poller bookkeeping');
    $end = strpos($source, 'function host_status_cache_check()');
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $postRun = substr($source, $start, $end - $start);
    $postRun = str_replace(test_php_function_source($source, 'poller_heartbeat_check'), '', $postRun);
    $main = array('multiple_poller_boost_check', 'poller_replicate_check', 'snmpagent_poller_bottom', 'boost_poller_bottom', 'dsstats_poller_bottom', 'dsdebug_poller_bottom', 'reports_poller_bottom', 'spikekill_poller_bottom', 'automation_poller_bottom', 'poller_maintenance', 'rrdcheck_poller_bottom', 'api_plugin_hook', 'bad_index_check', 'host_status_cache_check', 'poller_heartbeat_check');
    $all = array_merge($main, array('cacti_log', 'poller_recovery_flush_boost'));
    $program = '<?php namespace PollerPostRunProbe; $events=array();';
    foreach ($all as $name) {
        $program .= 'function ' . $name . '(...$args){$GLOBALS["events"][]="' . $name . '";}';
    }
    $program .= '$poller_id=' . $pollerId . ';$mibs=false;$config=array("connection"=>' . var_export($recovery ? 'recovery' : 'online', true) . ');';
    $program .= '$rrd_runs_failed=' . ($failed ? 'true' : 'false') . ';register_shutdown_function(function(){echo json_encode($GLOBALS["events"]);});' . $postRun;
    $file = tempnam(sys_get_temp_dir(), 'poller-post-run-');
    file_put_contents($file, $program);
    try {
        $process = proc_open(array(PHP_BINARY, $file), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe($failed ? 1 : 0, $error)->and($error)->toBe('');
        $remote = array('automation_poller_bottom', 'poller_maintenance', 'api_plugin_hook');
        if ($recovery) {
            $remote = array_merge(array('cacti_log', 'poller_recovery_flush_boost'), $remote);
        }
        expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe($pollerId === 1 ? $main : $remote);
    } finally {
        unlink($file);
    }
})->with(array(array(1, false, false), array(1, false, true), array(2, false, true), array(2, true, true)));


test('the production wait loop retries after a transient drain failure', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    $start = strpos($source, '$mtb = microtime(true);');
    $end = strpos($source, '// end the process if the runtime exceeds', $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $body = substr($source, $start, $end - $start);
    $program = '$calls=0;$poller_id=1;$rrds_processed=0;$poller_output_deferred=false;$rrdtool_pipe=false;'
        . 'function process_poller_output_batch(&$deferred,&$pipe){global $calls;$calls++;$deferred=$calls===1;return $deferred?0:3;}'
        . $body . $body
        . 'echo json_encode(array($calls,$rrds_processed,$poller_output_deferred,$rrd_write_failed));';
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('');
    expect(json_decode($output, true))->toBe(array(2, 3, false, true));
});


test('the final drain reports current failure state after a recovered waiting attempt', function ($failed) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    $start = strpos($source, '$rrds_processed += process_poller_output_batch(');
    $end = strpos($source, ' elseif ($config', $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $body = substr($source, $start, $end - $start);
    $signature = strpos($body, 'process_poller_output_batch(true,') !== false ? '$final,&$deferred,&$pipe' : '&$deferred,&$pipe';
    $program = '$rrds_processed=0;$poller_output_deferred=true;$rrd_write_failed=true;$rrdtool_pipe=false;'
        . 'function process_poller_output_batch(' . $signature . '){$deferred=' . ($failed ? 'true' : 'false') . ';return 3;}'
        . 'if(true){' . $body . 'echo json_encode(array($rrds_processed,$rrd_write_failed));';
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('');
    expect(json_decode($output, true))->toBe(array(3, $failed));
})->with(array(false, true));


test('a failed drain in an earlier run still fails a multi-run poller process', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    $loop = strpos($source, 'while ($poller_runs_completed < $poller_runs) {');
    $init = strpos($source, '$rrd_runs_failed = false;');
    $accumulate = '$rrd_runs_failed = $rrd_runs_failed || !empty($rrd_write_failed);';
    $position = strpos($source, $accumulate);
    $start = strpos($source, '// Finish poller bookkeeping');
    $end = strpos($source, 'function host_status_cache_check()');
    // Initialised once before the run loop and accumulated at the end of each run.
    expect($init)->not->toBeFalse()->and($init)->toBeLessThan($loop)
        ->and($position)->toBeGreaterThan($loop)->and($position)->toBeLessThan($start);
    $postRun = substr($source, $start, $end - $start);
    $postRun = str_replace(test_php_function_source($source, 'poller_heartbeat_check'), '', $postRun);
    $program = '<?php namespace PollerRunsProbe;';
    foreach (array('multiple_poller_boost_check', 'poller_replicate_check', 'snmpagent_poller_bottom', 'boost_poller_bottom', 'dsstats_poller_bottom', 'dsdebug_poller_bottom', 'reports_poller_bottom', 'spikekill_poller_bottom', 'automation_poller_bottom', 'poller_maintenance', 'rrdcheck_poller_bottom', 'api_plugin_hook', 'bad_index_check', 'host_status_cache_check', 'poller_heartbeat_check', 'cacti_log', 'poller_recovery_flush_boost') as $name) {
        $program .= 'function ' . $name . '(...$args){}';
    }
    // The first run's drain fails; the second run's drain succeeds.
    $program .= '$poller_id=1;$mibs=false;$config=array("connection"=>"online");$rrd_runs_failed=false;'
        . 'foreach (array(true, false) as $rrd_write_failed) {' . $accumulate . '}' . $postRun;
    $file = tempnam(sys_get_temp_dir(), 'poller-runs-');
    file_put_contents($file, $program);
    try {
        $process = proc_open(array(PHP_BINARY, $file), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(1, $error)->and($error)->toBe('');
    } finally {
        unlink($file);
    }
});
