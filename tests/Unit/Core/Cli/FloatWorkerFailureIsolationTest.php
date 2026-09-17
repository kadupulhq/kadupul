<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('float workers continue healthy queued files after a per-file failure', function ($mode) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/float_rrdfiles.php');
    $start = strpos($source, '$exit_status = 0;');
    $end = strpos($source, "float_debug('Polling Ending');", $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $program = '$mode=' . var_export($mode, true) . ';' . <<<'PHP'
$type='child';$thread_id=1;$step=false;$start_time=1;$end_time=2;
$deleted=array();$released=0;$unregistered=0;$logs=array();
function db_fetch_assoc_prepared(...$args){return array(array('local_data_id'=>1,'rrd_path'=>'bad.rrd'),array('local_data_id'=>2,'rrd_path'=>'good.rrd'));}
function cacti_sizeof($rows){return count($rows);}
function cacti_log($message,...$args){$GLOBALS['logs'][]=$message;}
function rrdtool_function_fetch($id,...$args){if($id===1){if($GLOBALS['mode']==='empty'){return array();}if($GLOBALS['mode']==='fetch'){throw new RuntimeException('fetch failure');}}return array('data_source_names'=>array('value'));}
function rrd_maintenance_acquire(...$args){return true;}
function rrd_maintenance_acquire_paths(...$args){return true;}
function rrd_maintenance_release($lock){$GLOBALS['released']++;}
function float_rrdfile($path,$id,...$args){if($id===1){throw new RuntimeException('rewrite failure');}return true;}
function db_execute_prepared($sql,$params){$GLOBALS['deleted'][]=$params[0];return true;}
function unregister_process(...$args){$GLOBALS['unregistered']++;}
PHP;
    $program .= substr($source, $start, $end - $start)
        . 'echo "RESULT:".json_encode(array($exit_status,$deleted,$released,$unregistered,$logs));';
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('');
    $result = json_decode(substr($output, strpos($output, 'RESULT:') + 7), true);
    expect(array_slice($result, 0, 4))->toBe(array(1, array(2), $mode === 'rewrite' ? 2 : 1, 1));
    expect(implode(' ', $result[4]))->toContain('Float DS[1] retained for retry');
})->with(array('empty', 'fetch', 'rewrite'));
