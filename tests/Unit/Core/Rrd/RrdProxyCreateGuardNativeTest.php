<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('unknown proxy existence never authorizes destructive RRD creation', function ($operation, $response) {
    if (!function_exists('socket_create_pair')) {
        $this->markTestSkipped('The sockets extension is required.');
    }
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/proxy-create-guard-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    file_put_contents($dir . '/global_arrays.php', '<?php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '';
    if ($coverage !== null) {
        $bootstrap = 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    require_once $root . '/tests/Helpers/PhpSource.php';
    $functions = file_get_contents($root . '/lib/functions.php');
    foreach (array('cacti_has_control_chars', 'cacti_rrdtool_valid_path', 'cacti_rrdtool_valid_path_token') as $function) {
        $bootstrap .= test_php_function_source($functions, $function);
    }
    $program = '<?php ' . $bootstrap . '$root=' . var_export($root, true) . ';$operation=' . var_export($operation, true)
        . ';$response=' . var_export($response, true) . ';' . <<<'PHP'
$config=array('rra_path'=>'/fixture','include_path'=>__DIR__);
require $root.'/include/global_constants.php';
function cacti_log(...$args){}
function read_config_option($key){return $key==='storage_location'?1:'';}
function get_data_source_path(...$args){return '/fixture/sample.rrd';}
function get_rrdtool_version(){return '1.7.2';}
function cacti_version_compare($a,$b,$op){return version_compare($a,$b,$op);}
function cacti_escapeshellarg($value){return escapeshellarg($value);}
function db_fetch_cell_prepared(...$args){throw new RuntimeException('Unknown existence reached create preparation');}
function db_fetch_assoc_prepared(...$args){throw new RuntimeException('Unknown existence reached create preparation');}
require $root.'/lib/rrd.php';
require $root.'/lib/boost.php';
$encryption=false;
if(!socket_create_pair(AF_UNIX,SOCK_STREAM,0,$sockets)){exit(2);}
foreach($sockets as $socket){socket_set_option($socket,SOL_SOCKET,SO_RCVTIMEO,array('sec'=>2,'usec'=>0));}
if($response!==null){$packet=$response."_EOP_\r\n_EOT_\r\n";if(socket_write($sockets[1],$packet)!==strlen($packet)){exit(3);}}
socket_shutdown($sockets[1],1);
$pipe=array($sockets[0],'fixture-key');$values='1700000060:42';
if($operation==='boost-update'){$result=boost_rrdtool_function_update(1,'/fixture/sample.rrd','value',$values,$pipe);}
elseif($operation==='boost-create'){$result=boost_rrdtool_function_create(1,false,$pipe);}
else{$result=rrdtool_function_create(1,false,$pipe);}
$command=socket_read($sockets[1],4096,PHP_BINARY_READ);
echo json_encode(array($result,$values,$command));
socket_close($sockets[0]);socket_close($sockets[1]);
PHP;
    file_put_contents($dir . '/probe.php', $program);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($error !== '') { throw new RuntimeException($error . $output); }
        expect($status)->toBe(0);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect($result[0])->toBe($operation === 'boost-update' ? 'ERROR: Unable to confirm RRD existence' : -1);
        expect($result[1])->toBe('1700000060:42');
        expect($result[2])->toStartWith('file_exists ')->not->toContain('create ')->not->toContain('update ');
        expect(substr_count($result[2], '_EOT_'))->toBe(1);
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($dir . '/*') as $file) { unlink($file); }
        rmdir($dir);
    }
})->with(array('boost-update', 'boost-create', 'direct-create'))->with(array(null, 'invalid OK u:0.00'));
