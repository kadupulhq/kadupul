<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
function maintenance_wait_file($path)
{
    $deadline = microtime(true) + 10;
    while (!file_exists($path) && microtime(true) < $deadline) {
        usleep(10000);
    }
    expect(file_exists($path))->toBeTrue();
}
beforeEach(function () {
    $this->oldConfig = $GLOBALS['config'] ?? null;
    $this->dir = sys_get_temp_dir() . '/rrd-maintenance-' . bin2hex(random_bytes(8));
    mkdir($this->dir, 0700);
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => $this->dir);
});
afterEach(function () {
    $GLOBALS['config'] = $this->oldConfig;
    foreach (glob($this->dir . '/*') as $file) {
        unlink($file);
    }
    rmdir($this->dir);
});
test('writers coexist and exclude maintenance until every writer finishes', function () {
    $one = rrd_maintenance_acquire();
    $two = rrd_maintenance_acquire();
    try {
        expect(is_resource($one))->toBeTrue()->and(is_resource($two))->toBeTrue();
        expect(rrd_maintenance_acquire(true))->toBeFalse();
        rrd_maintenance_release($one);
        expect(rrd_maintenance_acquire(true))->toBeFalse();
    } finally {
        rrd_maintenance_release($one);
        rrd_maintenance_release($two);
    }
    $exclusive = rrd_maintenance_acquire(true);
    try {
        expect(is_resource($exclusive))->toBeTrue();
    } finally {
        rrd_maintenance_release($exclusive);
    }
});
test('missing storage fails closed and Windows writers keep existing behavior', function () {
    $GLOBALS['config']['rra_path'] .= '/missing';
    expect(rrd_maintenance_acquire())->toBeFalse()->and(rrd_maintenance_acquire(true))->toBeFalse();
    $GLOBALS['config']['cacti_server_os'] = 'win32';
    expect(rrd_maintenance_acquire())->toBeTrue()->and(rrd_maintenance_acquire(true))->toBeFalse();
    rrd_maintenance_release(true);
    rrd_maintenance_release(false);
});
test('storage aliases lock the same inode without creating lock files', function () {
    $one = rrd_maintenance_acquire();
    symlink($this->dir, $this->dir . '/alias');
    $GLOBALS['config']['rra_path'] .= '/alias';
    try {
        expect(rrd_maintenance_acquire(true))->toBeFalse();
    } finally {
        rrd_maintenance_release($one);
    }
    $exclusive = rrd_maintenance_acquire(true);
    try {
        expect(is_resource($exclusive))->toBeTrue()->and(glob($this->dir . '/*'))->toBe(array($this->dir . '/alias'));
    } finally {
        rrd_maintenance_release($exclusive);
    }
});
test('real synchronous and queued updates retain their samples across maintenance', function ($explicitClose, $outputFlag) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required; CI provisions it.');
    }
    $rrd = $this->dir . '/source.rrd';
    $rrdcmd = escapeshellarg($binary);
    exec($rrdcmd . ' create ' . escapeshellarg($rrd) . ' --start 1000000000 --step 60 DS:value:GAUGE:600:U:U RRA:AVERAGE:0.5:1:20', $out, $status);
    expect($status)->toBe(0);
    $bootstrap = '<?php $config = ' . var_export(array('cacti_server_os' => 'unix', 'rra_path' => $this->dir, 'is_web' => false), true) . ';' .
        'define("CACTI_LOCALE", "en-US"); define("POLLER_VERBOSITY_DEBUG", 5);' .
        'define("RRDTOOL_OUTPUT_NULL", 0); define("RRDTOOL_OUTPUT_STDOUT", 1); define("RRDTOOL_OUTPUT_GRAPH_DATA", 2); define("RRDTOOL_OUTPUT_STDERR", 3); define("RRDTOOL_OUTPUT_RETURN_STDERR", 4);' .
        'function read_config_option($name) { return $name === "path_rrdtool" ? ' . var_export($binary, true) . ' : ""; }' .
        'function cacti_log(...$args) {} function cacti_session_close() {} function cacti_escapeshellarg($value) { return escapeshellarg($value); }' .
        'require ' . var_export(dirname(__DIR__, 4) . '/lib/rrd.php', true) . ';';
    $script = $this->dir . '/writer.php';
    file_put_contents($script, $bootstrap . 'touch(__DIR__ . "/started"); rrdtool_execute(array("update", __DIR__ . "/source.rrd", "1000000060:42"), false, ' . $outputFlag . '); touch(__DIR__ . "/finished");');
    $lock = rrd_maintenance_acquire(true);
    $process = proc_open(array(PHP_BINARY, $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    try {
        maintenance_wait_file($this->dir . '/started');
        usleep(100000);
        expect(file_exists($this->dir . '/finished'))->toBeFalse();
        expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000000');
    } finally {
        rrd_maintenance_release($lock);
    }
    maintenance_wait_file($this->dir . '/finished');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000060');
    expect(shell_exec($rrdcmd . ' lastupdate ' . escapeshellarg($rrd)))->toContain('42');
    $wrapper = $this->dir . '/delayed-rrd';
    file_put_contents($wrapper, "#!/bin/sh\nwhile [ ! -f " . escapeshellarg($this->dir . '/gate') . " ]; do sleep 0.01; done\nexec " . $rrdcmd . " \"\$@\"\n");
    chmod($wrapper, 0700);
    file_put_contents($script, str_replace(var_export($binary, true), var_export($wrapper, true), $bootstrap) .
        '$pipe = rrd_init(false); rrdtool_execute(array("update", __DIR__ . "/source.rrd", "1000000120:84"), false, RRDTOOL_OUTPUT_STDOUT, $pipe); touch(__DIR__ . "/queued");' . ($explicitClose ? 'rrd_close($pipe); touch(__DIR__ . "/closed");' : ''));
    $process = proc_open(array(PHP_BINARY, $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    try {
        maintenance_wait_file($this->dir . '/queued');
        expect(rrd_maintenance_acquire(true))->toBeFalse()->and(file_exists($this->dir . '/closed'))->toBeFalse();
        expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000060');
    } finally {
        touch($this->dir . '/gate');
    }
    if ($explicitClose) {
        maintenance_wait_file($this->dir . '/closed');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000120');
    expect(shell_exec($rrdcmd . ' lastupdate ' . escapeshellarg($rrd)))->toContain('84');
    $exclusive = rrd_maintenance_acquire(true);
    try {
        expect(is_resource($exclusive))->toBeTrue();
    } finally {
        rrd_maintenance_release($exclusive);
    }
    file_put_contents($this->dir . '/global_arrays.php', '<?php $data_source_types = array(1 => "GAUGE");');
    $tune = array('data_source_id' => 1, 'data-source-type' => 1, 'heartbeat' => 777, 'minimum' => '', 'maximum' => '', 'data-source-rename' => '');
    file_put_contents($script, $bootstrap . '$config["include_path"] = __DIR__;' .
        'function cacti_escapeshellcmd($value) { return escapeshellcmd($value); }' .
        'function get_data_source_item_name($id) { return "value"; } function get_data_source_path($id, $expand) { return __DIR__ . "/source.rrd"; }' .
        'touch(__DIR__ . "/tune-started"); rrdtool_function_tune(' . var_export($tune, true) . '); touch(__DIR__ . "/tune-finished");');
    $lock = rrd_maintenance_acquire(true);
    $process = proc_open(array(PHP_BINARY, $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    try {
        maintenance_wait_file($this->dir . '/tune-started');
        usleep(100000);
        expect(file_exists($this->dir . '/tune-finished'))->toBeFalse();
        expect(shell_exec($rrdcmd . ' info ' . escapeshellarg($rrd)))->toContain('minimal_heartbeat = 600');
    } finally {
        rrd_maintenance_release($lock);
    }
    maintenance_wait_file($this->dir . '/tune-finished');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    expect(shell_exec($rrdcmd . ' info ' . escapeshellarg($rrd)))->toContain('minimal_heartbeat = 777');
    expect(shell_exec($rrdcmd . ' lastupdate ' . escapeshellarg($rrd)))->toContain('84');
    file_put_contents($script, $bootstrap . '$pipe = fopen("php://temp", "w+");' .
        '$unsafe = rrdtool_execute("update unused.rrd 1000000180:99", false, RRDTOOL_OUTPUT_STDOUT, $pipe);' .
        '$config["rra_path"] = __DIR__ . "/missing"; $missingPipe = rrd_init();' .
        '$missingCommand = rrdtool_execute("update unused.rrd 1000000180:99", false, RRDTOOL_OUTPUT_STDOUT);' .
        'echo json_encode(array($unsafe, $missingPipe, $missingCommand)); fclose($pipe);');
    $process = proc_open(array(PHP_BINARY, $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('')->and(json_decode($stdout, true))->toBe(array(false, false, false));
})->with(array(array(true, 1), array(false, 1), array(true, 0), array(false, 0)));
