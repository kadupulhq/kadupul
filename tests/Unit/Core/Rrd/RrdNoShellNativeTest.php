<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// path_rrdtool holds a space and "$HOME". A shell would split the path at the
// space or expand the variable, so RRDtool runs here only without one.
test('RRDtool starts without a shell and receives its exact arguments', function ($mode) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-no-shell-' . bin2hex(random_bytes(8));
    $bin = $dir . '/bin dir $HOME';
    mkdir($bin, 0700, true);
    // Records each start and every line it reads, writes a marker to stdout and
    // stderr per command, and notes its exit only after a delay, so a close that
    // does not wait for it is visible.
    file_put_contents($bin . '/rrdtool', '#!' . PHP_BINARY . "\n<?php\n" . <<<'FAKE'
        $log = dirname(__DIR__) . '/rrdtool.log';
        file_put_contents($log, json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
        fwrite(STDERR, "stderr-marker\n");
        echo "stdout-marker\n";
        while (($line = fgets(STDIN)) !== false) {
            file_put_contents($log, json_encode(rtrim($line, "\r\n")) . "\n", FILE_APPEND);
            if (trim($line) === 'quit') {
                break;
            }
            echo "OK u:0.00 s:0.00 r:0.00\n";
            fflush(STDOUT);
        }
        usleep(300000);
        file_put_contents($log, "\"exited\"\n", FILE_APPEND);
        FAKE);
    chmod($bin . '/rrdtool', 0700);
    touch($dir . '/it is.rrd');
    file_put_contents($dir . '/global_arrays.php', '<?php $data_source_types = array(1 => "GAUGE");');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $program = '<?php ';
    if ($coverage !== null) {
        $program .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program .= '$root=' . var_export($root, true) . ';$mode=' . var_export($mode, true) . ';';
    $program .= <<<'SOURCE'
$config=array('cacti_server_os'=>'unix','rra_path'=>__DIR__,'include_path'=>__DIR__,'is_web'=>false);
require $root.'/include/global_constants.php';
define('CACTI_LOCALE','en-US');
function read_config_option($key){return $key==='path_rrdtool'?__DIR__.'/bin dir $HOME/rrdtool':'';}
function cacti_log($message,...$args){$GLOBALS['messages'][]=$message;}
function cacti_session_close(){}
// As the unix branch of the real one, which removes CR and LF.
function cacti_escapeshellarg($value){return escapeshellarg(str_replace(array("\r","\n"),'',$value));}
function cacti_escapeshellcmd($value){return escapeshellcmd($value);}
function get_data_source_item_name($id){return 'value';}
function get_data_source_path($id,$expand){return __DIR__.'/it is.rrd';}
require $root.'/lib/rrd.php';
$result=null;
if($mode==='writer-shutdown'){
    // Left open: the shutdown handler must close it and wait for the child.
    $result=rrdtool_execute('update it.rrd N:1',false,RRDTOOL_OUTPUT_NULL,rrd_init(false));
}elseif($mode==='acknowledged'||$mode==='writer-null'||$mode==='writer-terminal'){
    $pipe=rrd_init($mode==='writer-terminal',false,$mode==='acknowledged');
    $result=rrdtool_execute('update it.rrd N:1',false,$mode==='acknowledged'?RRDTOOL_OUTPUT_BOOLEAN:RRDTOOL_OUTPUT_NULL,$pipe);
    rrd_close($pipe);
}elseif($mode==='tune'){
    $result=rrdtool_function_tune(array('data_source_id'=>1,'data-source-type'=>1,'heartbeat'=>600,'minimum'=>'','maximum'=>'','data-source-rename'=>"it's \$HOME\r\n \"x\""));
}else{
    $result=rrdtool_execute('info it.rrd',false,$mode==='execute-stdout'?RRDTOOL_OUTPUT_STDOUT:RRDTOOL_OUTPUT_RETURN_STDERR);
}
// Anything after rrd_close() or the call itself returned sees the child gone.
// The open writer's child may not have started yet, so the test reads its log.
$log=$mode==='writer-shutdown'?array():array_map('json_decode',file(__DIR__.'/rrdtool.log',FILE_IGNORE_NEW_LINES));
echo "\n".json_encode(array($result,$log,$GLOBALS['messages']??array()));
SOURCE;
    file_put_contents($dir . '/run.php', $program);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/run.php'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $out . $err);
        $lines = explode("\n", $out);
        list($result, $log, $messages) = json_decode(array_pop($lines), true, 512, JSON_THROW_ON_ERROR);
        $printed = implode("\n", $lines);
        if ($mode === 'writer-shutdown') {
            // Read once this process has exited: its shutdown handler waited for the child.
            $log = array_map('json_decode', file($dir . '/rrdtool.log', FILE_IGNORE_NEW_LINES));
            expect($log)->toBe(array(array('-'), ' update it.rrd N:1', 'exited'));
        }
        expect(end($log))->toBe('exited');
        if ($mode === 'tune') {
            // CR and LF leave the rename; the log keeps the old shell-quoted form.
            $path = realpath($dir) . '/it is.rrd';
            expect($result)->toBeNull()
                ->and($log)->toBe(array(array('tune', $path, '--heartbeat', 'value:600', '--data-source-type', 'value:GAUGE',
                    '--data-source-rename', "value:it's \$HOME \"x\""), 'exited'))
                ->and($messages)->toBe(array('CACTI2RRD: ' . escapeshellcmd(realpath($bin) . '/rrdtool') . ' tune ' . escapeshellarg($path)
                    . ' --heartbeat ' . escapeshellarg('value:600') . ' --data-source-type ' . escapeshellarg('value:GAUGE')
                    . ' --data-source-rename ' . escapeshellarg("value:it's \$HOME \"x\"")))
                ->and($printed)->toBe('')->and($err)->toBe("stderr-marker\n");
        } elseif ($mode === 'execute-stdout' || $mode === 'execute-stderr') {
            expect($log)->toBe(array(array('-'), 'info it.rrd', 'quit', 'exited'))->and($printed)->toBe('');
            if ($mode === 'execute-stdout') {
                expect($result)->toBe("stdout-marker\n")->and($err)->toBe("stderr-marker\n");
            } else {
                expect($result)->toBe("stderr-marker\nstdout-marker\n")->and($err)->toBe('');
            }
        } else {
            // The legacy writer has always sent a leading blank.
            expect($log)->toBe(array(array('-'), ($mode === 'acknowledged' ? '' : ' ') . 'update it.rrd N:1', 'exited'));
            if ($mode === 'acknowledged') {
                expect($result)->toBeTrue()->and($printed)->toBe('')->and($err)->toBe('');
            } elseif ($mode === 'writer-null' || $mode === 'writer-shutdown') {
                expect($result)->toBeNull()->and($printed)->toBe('')->and($err)->toBe('');
            } else {
                expect($result)->toBeNull()->and($printed)->toBe("stdout-marker\nOK u:0.00 s:0.00 r:0.00\n")->and($err)->toBe("stderr-marker\n");
            }
        }
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (array_merge(glob($bin . '/*'), glob($dir . '/*')) as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($dir);
    }
})->with(array('acknowledged', 'writer-null', 'writer-shutdown', 'writer-terminal', 'execute-stdout', 'execute-stderr', 'tune'));
