<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('native RRD transformations preserve originals on failure and verify restored structure', function ($operation, $mode) {
    $binary = getenv('RRDTOOL_TEST_BINARY');
    if (!$binary || !is_executable($binary)) {
        $this->markTestSkipped('RRDTOOL_TEST_BINARY is required');
    }
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/rrd-transform-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $server = <<<'SERVER'
<?php
while (($command = fgets(STDIN)) !== false) {
    if (trim($command) === 'quit') { break; }
    if (getenv('RRD_TRANSFORM_MODE') === 'bad-dump' && strpos($command, 'dump ') === 0) { echo "ERROR: injected dump failure\n"; fflush(STDOUT); continue; }
    if (strpos($command, 'restore ') === 0) { echo "ERROR: injected restore failure\n"; fflush(STDOUT); continue; }
    $process = proc_open(array(getenv('RRDTOOL_TEST_BINARY'), '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $streams);
    fwrite($streams[0], $command . "quit\n"); fclose($streams[0]);
    stream_copy_to_stream($streams[1], STDOUT); fclose($streams[1]); proc_close($process); fflush(STDOUT);
}
SERVER;
    file_put_contents($directory . '/fail-restore', '#!' . PHP_BINARY . "\n" . $server);
    chmod($directory . '/fail-restore', 0700);
    $program = '<?php ';
    if ($coverage !== null) {
        $program .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program .= '$root=' . var_export($root, true) . ';$operation=' . var_export($operation, true) . ';$mode=' . var_export($mode, true) . ';';
    $program .= <<<'SOURCE'
putenv('RRD_TRANSFORM_MODE='.$mode);
$config=array('cacti_server_os'=>'unix','rra_path'=>__DIR__,'is_web'=>false);
require $root.'/include/global_constants.php';
define('CACTI_LOCALE','en-US');
$data_source_types=array(5=>'COMPUTE');
function read_config_option($key){return $key==='path_rrdtool'?(in_array($GLOBALS['mode'],array('restore-failed','bad-dump'),true)?__DIR__.'/fail-restore':getenv('RRDTOOL_TEST_BINARY')):'';}
function cacti_log($message,...$args){$GLOBALS['logged'][]=str_replace(__DIR__,'<DIR>',$message);}
$logged=array();$warnings=array();
set_error_handler(function($level,$message)use(&$warnings){$warnings[]=$level.': '.str_replace(__DIR__,'<DIR>',$message);return true;});
function cacti_session_close(){}
function cacti_escapeshellarg($value){return escapeshellarg($value);}
function cacti_sizeof($value){return is_array($value)?count($value):0;}
function __($message,...$args){return $args?vsprintf($message,$args):$message;}
require $root.'/lib/rrd.php';
$file=__DIR__.'/live.rrd';
$process=proc_open(array(getenv('RRDTOOL_TEST_BINARY'),'create',$file,'--start','1700000000','--step','60','DS:value:GAUGE:120:U:U','RRA:AVERAGE:0.5:1:10','RRA:MAX:0.5:1:10'),array(1=>array('pipe','w'),2=>array('pipe','w')),$streams);
foreach($streams as $stream){stream_get_contents($stream);fclose($stream);}
if(proc_close($process)!==0){exit(2);}
$before=hash_file('sha256',$file);
if($mode==='readonly'){chmod($file,0400);}
if($mode==='save-failed'){mkdir($file.'.xml');}
$rra=array('cf'=>'AVERAGE','pdp_per_row'=>1,'xff'=>0.5,'rows'=>10);
ob_start();
if($operation==='add'||$operation==='compute'){
 $result=rrd_datasource_add(array($file),array(array('name'=>'added','type'=>$operation==='compute'?'COMPUTE':'GAUGE','cdef'=>'value,2,*','heartbeat'=>120,'min'=>'NaN','max'=>'NaN')),$mode==='debug');
}elseif($operation==='delete'){
 $result=rrd_rra_delete(array($file),array($rra),$mode==='debug');
}else{
 $result=rrd_rra_clone(array($file),'MIN',array($rra),$mode==='debug');
}
$printed=ob_get_clean();
if($mode==='save-failed'){rmdir($file.'.xml');}
putenv('RRDCACHED_ADDRESS');
chmod($file,0600);
$process=proc_open(array(getenv('RRDTOOL_TEST_BINARY'),'info',$file),array(1=>array('pipe','w'),2=>array('pipe','w')),$streams);
$info=stream_get_contents($streams[1]);$error=stream_get_contents($streams[2]);fclose($streams[1]);fclose($streams[2]);
if(proc_close($process)!==0||$error!==''){exit(3);}
$lease=rrd_maintenance_acquire(false,false,0);
$released=is_resource($lease);rrd_maintenance_release($lease);
echo json_encode(array('result'=>is_array($result)?str_replace(__DIR__,'<DIR>',$result):$result,'unchanged'=>$before===hash_file('sha256',$file),'xml'=>is_file($file.'.xml'),'info'=>$info,'printed'=>$printed,'released'=>$released,'logged'=>$logged,'warnings'=>$warnings));
SOURCE;
    file_put_contents($directory . '/run.php', $program);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/run.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException($out . $err);
        }
        expect($err)->toBe('');
        $result = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        expect($result['released'])->toBeTrue();
        if ($mode === 'success') {
            expect($result['result'])->toBeTrue()->and($result['unchanged'])->toBeFalse()->and($result['xml'])->toBeFalse();
            if (in_array($operation, array('add', 'compute'), true)) {
                expect($result['info'])->toContain('ds[added].index = 1');
            } elseif ($operation === 'delete') {
                expect($result['info'])->not->toContain('"AVERAGE"')->toContain('"MAX"');
            } else {
                expect($result['info'])->toContain('"MIN"')->toContain('"AVERAGE"');
            }
        } elseif ($mode === 'debug') {
            expect($result['result'])->toBeTrue()->and($result['unchanged'])->toBeTrue()->and($result['xml'])->toBeFalse()->and($result['printed'])->toContain('<rrd>');
        } else {
            expect($result['result'])->toBeArray()->and($result['unchanged'])->toBeTrue()->and($result['xml'])->toBe(!in_array($mode, array('bad-dump', 'save-failed'), true));
        }
        // The exact answer, log line and warning of each path, which the
        // three operations share apart from their wording.
        $added = in_array($operation, array('add', 'compute'), true);
        $logged = array('add' => 'Added Data Source(s) to', 'compute' => 'Added Data Source(s) to', 'delete' => 'Deleted RRA(s) from', 'clone' => 'Cloned RRA(s) in')[$operation] . ' RRDfile: <DIR>/live.rrd';
        $expected = array(
            'success' => array(true, array($logged), array()),
            'debug' => array(true, array(), array()),
            'readonly' => array(array('err_msg' => 'ERROR: RRDfile <DIR>/live.rrd not writeable'), array(), array()),
            'restore-failed' => array(array('err_msg' => 'RRD restore failed; original and recovery XML preserved. See application log.'), array('ERROR: RRD restore failed; original preserved and recovery XML retained at <DIR>/live.rrd.xml'), array()),
            'bad-dump' => array(array('err_msg' => 'Error while parsing the XML of ' . ($added ? 'rrdtool' : 'RRDtool') . ' dump'), array(), array()),
            'save-failed' => array(array('err_msg' => 'ERROR while writing XML file: <DIR>/live.rrd.xml'), array(), array('2: DOMDocument::save(<DIR>/live.rrd.xml): Failed to open stream: Is a directory')),
        )[$mode];
        expect(array($result['result'], $result['logged'], $result['warnings']))->toBe($expected);
        // The dump reply reaches the output buffer; debug adds the modified XML.
        if ($mode === 'bad-dump') {
            expect($result['printed'])->toBe("ERROR: injected dump failure\n");
        } else {
            expect(substr_count($result['printed'], '<rrd>'))->toBe($mode === 'debug' ? 2 : 1);
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(array('add', 'delete', 'clone', 'compute'))->with(array('success', 'debug', 'readonly', 'restore-failed', 'bad-dump', 'save-failed'));
