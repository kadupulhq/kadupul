<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Run one create guard against a fake proxy that answers file_exists with $response. */
function rrd_proxy_create_guard_run($test, string $operation, ?string $response): array
{
    if (!function_exists('socket_create_pair')) {
        $test->markTestSkipped('The sockets extension is required.');
    }
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/proxy-create-guard-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    file_put_contents($dir . '/global_arrays.php', '<?php $data_source_types = array(1 => "GAUGE"); $consolidation_functions = array(1 => "AVERAGE");');
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    $bootstrap = '';
    if ($coverage !== null) {
        $bootstrap = 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program = '<?php ' . $bootstrap . '$root=' . var_export($root, true) . ';$operation=' . var_export($operation, true)
        . ';$response=' . var_export($response, true) . ';' . <<<'PHP'
// max:<boost|direct>:<alias> creates an RRD whose maximum is the substituted alias.
$max=str_starts_with($operation,'max:')?explode(':',$operation,3):null;
$config=array('rra_path'=>'/fixture','include_path'=>__DIR__,'cacti_server_os'=>$max?'win32':'unix');
require $root.'/include/global_constants.php';
function cacti_log(...$args){}
function cacti_sizeof($value){return is_array($value)?count($value):0;}
function read_config_option($key){return $key==='storage_location'?1:'';}
function get_data_source_path(...$args){return '/fixture/sample.rrd';}
function get_rrdtool_version(){return '1.7.2';}
function cacti_version_compare($a,$b,$op){return version_compare($a,$b,$op);}
function cacti_escapeshellarg($value){return escapeshellarg($value);}
function db_fetch_cell_prepared($sql,...$args){if($GLOBALS['max']){return str_contains($sql,'data_template_id')?'0':'';}throw new RuntimeException('Unknown existence reached create preparation');}
function db_fetch_assoc_prepared($sql,...$args){
    if(!$GLOBALS['max']){throw new RuntimeException('Unknown existence reached create preparation');}
    if(str_contains($sql,'data_source_profiles_cf')){return array(array('rrd_step'=>'300','x_files_factor'=>'0.5','steps'=>'1','rows'=>'600','consolidation_function_id'=>'1'));}
    return array(array('id'=>'301','data_source_name'=>'value','rrd_heartbeat'=>'600','rrd_minimum'=>'0','rrd_maximum'=>'|query_ifAlias|','data_source_type_id'=>'1'));
}
function db_fetch_row_prepared($sql,...$args){return array('id'=>'1','data_template_id'=>'0','host_id'=>'3','snmp_query_id'=>'1','snmp_index'=>'2');}
function get_data_source_item_name(...$args){return 'value';}
function substitute_snmp_query_data(...$args){return $GLOBALS['max'][2];}
require $root.'/lib/rrd.php';
require $root.'/lib/boost.php';
$encryption=false;
if(!socket_create_pair(AF_UNIX,SOCK_STREAM,0,$sockets)){exit(2);}
foreach($sockets as $socket){socket_set_option($socket,SOL_SOCKET,SO_RCVTIMEO,array('sec'=>2,'usec'=>0));}
if($response!==null){$packet=$response."_EOP_\r\n_EOT_\r\n";if(socket_write($sockets[1],$packet)!==strlen($packet)){exit(3);}}
socket_shutdown($sockets[1],1);
$pipe=array($sockets[0],'fixture-key');$values='1700000060:42';
if($operation==='boost-update'){$result=boost_rrdtool_function_update(1,'/fixture/sample.rrd','value',$values,$pipe);}
elseif($operation==='update'||$operation==='update-unsafe'){$path=$operation==='update'?'/fixture/sample.rrd':"/fixture/it's a.rrd";$result=rrdtool_function_update(array($path=>array('local_data_id'=>1,'data_template_id'=>0,'times'=>array(1700000060=>array('value'=>'42')))),$pipe);}
elseif($operation==='paths'){$result=array(rrdtool_command_argument('/fixture/sample.rrd'),rrdtool_command_argument('/fixture/it s.rrd'),rrdtool_command_argument("/fixture/it's.rrd"));}
elseif($max){$result=$max[1]==='boost'?boost_rrdtool_function_create(1,false,$pipe):rrdtool_function_create(1,false,$pipe);}
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
        if ($error !== '') {
            throw new RuntimeException($error . $output);
        }
        expect($status)->toBe(0);
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

test('unknown proxy existence never authorizes destructive RRD creation', function ($operation, $response) {
    $result = rrd_proxy_create_guard_run($this, $operation, $response);
    expect($result[0])->toBe($operation === 'boost-update' ? 'ERROR: Unable to confirm RRD existence' : -1);
    expect($result[1])->toBe('1700000060:42');
    expect($result[2])->toStartWith('file_exists ')->not->toContain('create ')->not->toContain('update ');
    expect(substr_count($result[2], '_EOT_'))->toBe(1);
})->with(array('boost-update', 'boost-create', 'direct-create'))->with(array(null, 'invalid OK u:0.00'));

test('an existing proxied RRD is checked with a bare path and never recreated', function ($operation) {
    $result = rrd_proxy_create_guard_run($this, $operation, 'OK u:0.00 s:0.00 r:0.00');

    expect($result[0])->toBe(-1)
        ->and($result[2])->toBe("file_exists ./sample.rrd_EOT_\r\n");
})->with(array('boost-create', 'direct-create'));

test('proxied updates carry a bare path, and a path the proxy cannot carry is not sent', function () {
    // The fake proxy acknowledges only file_exists, so each update ends unacknowledged.
    $boost = rrd_proxy_create_guard_run($this, 'boost-update', 'OK u:0.00 s:0.00 r:0.00');
    expect($boost[0])->toBe('ERROR: RRDtool did not acknowledge the update')
        ->and($boost[2])->toBe("file_exists ./sample.rrd_EOT_\r\nupdate ./sample.rrd --skip-past-updates --template value 1700000060:42_EOT_\r\n");

    $direct = rrd_proxy_create_guard_run($this, 'update', 'OK u:0.00 s:0.00 r:0.00');
    expect($direct[0])->toBeFalse()
        ->and($direct[2])->toBe("file_exists ./sample.rrd_EOT_\r\nupdate ./sample.rrd --skip-past-updates --template value 1700000060:42_EOT_\r\n");

    $unsafe = rrd_proxy_create_guard_run($this, 'update-unsafe', 'OK u:0.00 s:0.00 r:0.00');
    expect($unsafe[0])->toBeFalse()->and($unsafe[2])->toBeFalse();

    // Create writes its path the same way as update.
    $paths = rrd_proxy_create_guard_run($this, 'paths', null);
    expect($paths[0])->toBe(array('/fixture/sample.rrd', false, false));
});

test('a proxied create sends a substituted maximum bare, or not at all when the proxy cannot carry it', function ($function) {
    $missing = "ERROR: opening './sample.rrd': No such file or directory";

    // A number goes as it is, and a single safe token stays bare because the proxy would keep quotes as text.
    foreach (array('100' => 'DS:value:GAUGE:600:0:100', 'fast' => 'DS:value:GAUGE:600:0:fast') as $alias => $ds) {
        $result = rrd_proxy_create_guard_run($this, 'max:' . $function . ':' . $alias, $missing);
        expect($result[2])->toStartWith("file_exists ./sample.rrd_EOT_\r\ncreate ./sample.rrd ")
            ->toContain($ds . ' ')->not->toContain("'");
    }

    // More than one token is refused before anything but the existence check is sent.
    foreach (array('10 --daemon x', "it's") as $alias) {
        $result = rrd_proxy_create_guard_run($this, 'max:' . $function . ':' . $alias, $missing);
        expect($result[0])->toBeFalse()->and($result[2])->toBe("file_exists ./sample.rrd_EOT_\r\n");
    }
})->with(array('direct', 'boost'));
