<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('boolean RRD requests never overtake a caller supplied legacy pipe', function ($acknowledged) {
    $binary = getenv('RRDTOOL_TEST_BINARY');
    if (!$binary || !is_executable($binary)) {
        $this->markTestSkipped('RRDTOOL_TEST_BINARY is required');
    }
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-legacy-boolean-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $program = '<?php ';
    if ($coverage !== null) {
        $program .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program .= '$root=' . var_export($root, true) . ';$acknowledged=' . var_export($acknowledged, true) . ';';
    $program .= <<<'SOURCE'
$config=array('cacti_server_os'=>'unix','rra_path'=>__DIR__,'is_web'=>false);
require $root.'/include/global_constants.php';
define('CACTI_LOCALE','en-US');
function read_config_option($key){return $key==='path_rrdtool'?getenv('RRDTOOL_TEST_BINARY'):'';}
function cacti_log($message,...$args){$GLOBALS['messages'][]=$message;}
function cacti_session_close(){}
function cacti_escapeshellarg($value){return escapeshellarg($value);}
function cacti_escapeshellcmd($value){return escapeshellcmd($value);}
require $root.'/lib/rrd.php';
$file=__DIR__.'/sample.rrd';
$pipe=rrd_init(false,false,$acknowledged);
if(!is_resource($pipe)){exit(2);}
rrdtool_execute(array('create',$file,'--start','1700000000','--step','60','DS:value:GAUGE:120:U:U','RRA:AVERAGE:0.5:1:10'),false,RRDTOOL_OUTPUT_NULL,$pipe);
$result=rrdtool_execute(array('update',$file,'1700000060:10'),false,RRDTOOL_OUTPUT_BOOLEAN,$pipe);
rrd_close($pipe);
$pipe=rrd_init(false,false,true);
$last=rrdtool_execute(array('last',$file),false,RRDTOOL_OUTPUT_STDOUT,$pipe);
rrd_close($pipe);
echo json_encode(array($result,trim($last),$GLOBALS['messages']??array()));
SOURCE;
    file_put_contents($dir . '/run.php', $program);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/run.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($out . $err);
        }
        expect($err)->toBe('');
        $result = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        expect(array_slice($result, 0, 2))->toBe(array($acknowledged, $acknowledged ? '1700000060' : '1700000000'));
        if (!$acknowledged) {
            expect(implode(' ', $result[2]))->toContain('command not submitted');
        }
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array(false, true));
