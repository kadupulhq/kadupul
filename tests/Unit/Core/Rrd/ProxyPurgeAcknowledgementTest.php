<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('proxy purge requires an explicit setup acknowledgement before destructive commands', function ($ack) {
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
    $source = test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/poller_maintenance.php'), 'remove_files');
    $program = '$ack=' . var_export($ack, true) . ';' . <<<'PHP'
$config=array('rra_path'=>'/fixture','base_path'=>'/fixture');$closed=0;$commands=array();
define('RRDTOOL_OUTPUT_BOOLEAN',4);
function rrd_maintenance_cleanup_supported(){return true;}
function maint_debug(...$args){}
function cacti_sizeof($value){return count($value);}
function read_config_option($key){return $key==='storage_location'?1:'';}
function rrd_init(...$args){return 'proxy-pipe';}
function rrd_close($pipe){$GLOBALS['closed']++;}
function rrdtool_execute($command,...$args){$GLOBALS['commands'][]=$command;return $GLOBALS['ack'];}
function db_execute_prepared(...$args){throw new RuntimeException('Unacknowledged purge removed queue rows');}
PHP;
    $program .= $source . '$result=remove_files($ack===true?array():array(array("name"=>"existing.rrd","action"=>"1","local_data_id"=>1)));echo json_encode(array($result,$closed,$commands));';
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($error !== '') {
        throw new RuntimeException($error);
    }
    expect($status)->toBe(0);
    expect(json_decode($output, true))->toBe(array($ack === true, 1, array('setcnn timeout off')));
})->with(array(true, false, null, 'OK', 1, 0));
