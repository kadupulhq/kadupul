<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
function maintenance_wait_file($path) {
    $deadline = microtime(true) + 10;
    while (!file_exists($path) && microtime(true) < $deadline) { usleep(10000); }
    expect(file_exists($path))->toBeTrue();
}
beforeEach(function () {
    $this->expectedChildReports = 0;
    $this->oldConfig = $GLOBALS['config'] ?? null;
    $this->dir = sys_get_temp_dir() . '/rrd-maintenance-' . bin2hex(random_bytes(8));
    mkdir($this->dir, 0700);
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => $this->dir);
});
afterEach(function () {
    $parentCoverage = $this->getTestResultObject()->getCodeCoverage();
    $childReports = glob($this->dir . '/*.coverage');
    if ($parentCoverage !== null) {
        expect($childReports)->toHaveCount($this->expectedChildReports);
        foreach ($childReports as $report) {
            // Reports are written by our own PHP children in the owned 0700
            // fixture directory; this is PHPUnit's native coverage object.
            $childCoverage = unserialize(file_get_contents($report));
            expect($childCoverage)->toBeInstanceOf(SebastianBergmann\CodeCoverage\CodeCoverage::class);
            $parentCoverage->merge($childCoverage);
        }
    }
    $GLOBALS['config'] = $this->oldConfig;
    foreach (glob($this->dir . '/*') as $file) { unlink($file); }
    rmdir($this->dir);
});
test('writers coexist and exclude maintenance until every writer finishes', function () {
    $one = rrd_maintenance_acquire(); $two = rrd_maintenance_acquire();
    try {
        expect(is_resource($one))->toBeTrue()->and(is_resource($two))->toBeTrue();
        expect(rrd_maintenance_acquire(true))->toBeFalse();
        rrd_maintenance_release($one);
        expect(rrd_maintenance_acquire(true))->toBeFalse();
    } finally { rrd_maintenance_release($one); rrd_maintenance_release($two); }
    $exclusive = rrd_maintenance_acquire(true);
    try { expect(is_resource($exclusive))->toBeTrue(); } finally { rrd_maintenance_release($exclusive); }
});
test('missing storage fails closed and Windows writers keep existing behavior', function () {
    $GLOBALS['config']['rra_path'] .= '/missing';
    expect(rrd_maintenance_acquire())->toBeFalse()->and(rrd_maintenance_acquire(true))->toBeFalse();
    $GLOBALS['config']['cacti_server_os'] = 'win32';
    expect(rrd_maintenance_acquire())->toBeTrue()->and(rrd_maintenance_acquire(true))->toBeFalse();
    rrd_maintenance_release(true); rrd_maintenance_release(false);
});
test('storage aliases lock the same inode without creating lock files', function () {
    $one = rrd_maintenance_acquire(); symlink($this->dir, $this->dir . '/alias');
    $GLOBALS['config']['rra_path'] .= '/alias';
    try { expect(rrd_maintenance_acquire(true))->toBeFalse(); } finally { rrd_maintenance_release($one); }
    $exclusive = rrd_maintenance_acquire(true);
    try { expect(is_resource($exclusive))->toBeTrue()->and(glob($this->dir . '/*'))->toBe(array($this->dir . '/alias')); }
    finally { rrd_maintenance_release($exclusive); }
});
test('real synchronous and queued updates retain their samples across maintenance', function ($explicitClose, $outputFlag) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) { $this->markTestSkipped('Real RRDtool is required; CI provisions it.'); }
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
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 4;
        $bootstrap = '<?php define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export(dirname(__DIR__, 3) . '/fixtures/rrd-process-coverage.php', true) . ';' . substr($bootstrap, 5);
    }
    $script = $this->dir . '/writer.php';
    file_put_contents($script, $bootstrap . 'touch(__DIR__ . "/started"); rrdtool_execute(array("update", __DIR__ . "/source.rrd", "1000000060:42"), false, ' . $outputFlag . '); touch(__DIR__ . "/finished");');
    $lock = rrd_maintenance_acquire(true);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . dirname(__DIR__, 4), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    try {
        maintenance_wait_file($this->dir . '/started'); usleep(100000);
        expect(file_exists($this->dir . '/finished'))->toBeFalse();
        expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000000');
        $originalInode = stat($rrd)['ino'];
        copy($rrd, $rrd . '.replacement'); rename($rrd . '.replacement', $rrd);
        clearstatcache(true, $rrd);
        expect(stat($rrd)['ino'])->not->toBe($originalInode);
    } finally { rrd_maintenance_release($lock); }
    maintenance_wait_file($this->dir . '/finished');
    fclose($pipes[0]); stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000060');
    expect(shell_exec($rrdcmd . ' lastupdate ' . escapeshellarg($rrd)))->toContain('42');
    $wrapper = $this->dir . '/delayed-rrd';
    file_put_contents($wrapper, "#!/bin/sh\nwhile [ ! -f " . escapeshellarg($this->dir . '/gate') . " ]; do sleep 0.01; done\nexec " . $rrdcmd . " \"\$@\"\n"); chmod($wrapper, 0700);
    file_put_contents($script, str_replace(var_export($binary, true), var_export($wrapper, true), $bootstrap) .
        '$pipe = rrd_init(false); rrdtool_execute(array("update", __DIR__ . "/source.rrd", "1000000120:84"), false, RRDTOOL_OUTPUT_STDOUT, $pipe); touch(__DIR__ . "/queued");' . ($explicitClose ? 'rrd_close($pipe); touch(__DIR__ . "/closed");' : ''));
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . dirname(__DIR__, 4), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    try {
        maintenance_wait_file($this->dir . '/queued');
        expect(rrd_maintenance_acquire(true))->toBeFalse()->and(file_exists($this->dir . '/closed'))->toBeFalse();
        expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000060');
    } finally { touch($this->dir . '/gate'); }
    if ($explicitClose) { maintenance_wait_file($this->dir . '/closed'); }
    fclose($pipes[0]); stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000120');
    expect(shell_exec($rrdcmd . ' lastupdate ' . escapeshellarg($rrd)))->toContain('84');
    $exclusive = rrd_maintenance_acquire(true);
    try { expect(is_resource($exclusive))->toBeTrue(); } finally { rrd_maintenance_release($exclusive); }
    file_put_contents($this->dir . '/global_arrays.php', '<?php $data_source_types = array(1 => "GAUGE");');
    $tune = array('data_source_id' => 1, 'data-source-type' => 1, 'heartbeat' => 777, 'minimum' => '', 'maximum' => '', 'data-source-rename' => '');
    file_put_contents($script, $bootstrap . '$config["include_path"] = __DIR__;' .
        'function cacti_escapeshellcmd($value) { return escapeshellcmd($value); }' .
        'function get_data_source_item_name($id) { return "value"; } function get_data_source_path($id, $expand) { return __DIR__ . "/source.rrd"; }' .
        'touch(__DIR__ . "/tune-started"); rrdtool_function_tune(' . var_export($tune, true) . ');' .
        'putenv("RRDCACHED_ADDRESS=unix:/unavailable-test-cache"); rrdtool_function_tune(array("heartbeat" => 999));' .
        'touch(__DIR__ . "/tune-finished");');
    $lock = rrd_maintenance_acquire(true);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . dirname(__DIR__, 4), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    try {
        maintenance_wait_file($this->dir . '/tune-started'); usleep(100000);
        expect(file_exists($this->dir . '/tune-finished'))->toBeFalse();
        expect(shell_exec($rrdcmd . ' info ' . escapeshellarg($rrd)))->toContain('minimal_heartbeat = 600');
    } finally { rrd_maintenance_release($lock); }
    maintenance_wait_file($this->dir . '/tune-finished');
    fclose($pipes[0]); stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    expect(shell_exec($rrdcmd . ' info ' . escapeshellarg($rrd)))->toContain('minimal_heartbeat = 777');
    expect(shell_exec($rrdcmd . ' lastupdate ' . escapeshellarg($rrd)))->toContain('84');
    file_put_contents($script, $bootstrap . '$pipe = fopen("php://temp", "w+");' .
        '$unsafe = rrdtool_execute("update unused.rrd 1000000180:99", false, RRDTOOL_OUTPUT_STDOUT, $pipe);' .
        '$config["rra_path"] = __DIR__ . "/missing"; $missingPipe = rrd_init(); rrd_close($missingPipe);' .
        '$missingOperation = rrd_with_pipe(function ($owned) { return $owned; });' .
        '$missingCommand = rrdtool_execute("update unused.rrd 1000000180:99", false, RRDTOOL_OUTPUT_STDOUT);' .
        'echo json_encode(array($unsafe, $missingPipe, $missingCommand, $missingOperation)); fclose($pipe);');
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . dirname(__DIR__, 4), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    expect($stderr)->toBe('')->and(proc_close($process))->toBe(0)->and(json_decode($stdout, true))->toBe(array(false, false, false, false));
    if (function_exists('pcntl_waitpid')) {
        if ($this->getTestResultObject()->getCodeCoverage() !== null) { $this->expectedChildReports++; }
        file_put_contents($script, $bootstrap . 'require_once ' . var_export(dirname(__DIR__, 4) . '/lib/rrd_maintenance.php', true) . ';' .
            '$pipe = popen("exec false", "w"); if (pcntl_waitpid(-1, $status) < 1) { throw new RuntimeException("Child was not reaped"); }' .
            'rrd_maintenance_pipe($pipe, rrd_maintenance_acquire()); set_error_handler(function () { return true; });' .
            'try { rrdtool_execute(array("update", __DIR__ . "/source.rrd", "1000000180:126"), false, RRDTOOL_OUTPUT_STDOUT, $pipe); } finally { restore_error_handler(); }' .
            '$lock = rrd_maintenance_acquire(true); $released = is_resource($lock); rrd_maintenance_release($lock);' .
            'rrdtool_execute(array("update", __DIR__ . "/source.rrd", "1000000240:168"), false, RRDTOOL_OUTPUT_STDOUT, $pipe);' .
            'echo json_encode(array($released, is_resource($pipe)));');
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . dirname(__DIR__, 4), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        expect($stderr)->toBe('')->and(proc_close($process))->toBe(0)->and($stdout)->toEndWith('[true,false]')->not->toContain('ERROR:');
        expect(trim(shell_exec($rrdcmd . ' last ' . escapeshellarg($rrd))))->toBe('1000000240');
        expect(shell_exec($rrdcmd . ' lastupdate ' . escapeshellarg($rrd)))->toContain('168');
    }
})->with(array(array(true, 1), array(false, 1), array(true, 0), array(false, 0)));

test('an unreadable RRA directory fails closed', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Root bypasses directory read permissions; the unprivileged CI run covers this case.');
    }
    $path = $this->dir . '/unreadable'; mkdir($path, 0000);
    $GLOBALS['config']['rra_path'] = $path;
    try { expect(rrd_maintenance_acquire())->toBeFalse()->and(rrd_maintenance_acquire(true))->toBeFalse(); }
    finally { chmod($path, 0700); rmdir($path); }
});

test('a storage directory replaced while a writer waits is rejected', function ($exclusiveWait) {
    if (!is_dir('/proc/self/fd')) { $this->markTestSkipped('Linux descriptor inspection synchronizes this directory replacement test.'); }
    $path = $this->dir . '/store'; mkdir($path, 0700);
    $GLOBALS['config']['rra_path'] = $path;
    $lock = rrd_maintenance_acquire(true);
    $script = $this->dir . '/waiting-writer.php';
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export(dirname(__DIR__, 3) . '/fixtures/rrd-process-coverage.php', true) . ';';
    }
    file_put_contents($script, $bootstrap . '$config = ' . var_export($GLOBALS['config'], true) . ';' .
        'require ' . var_export(dirname(__DIR__, 4) . '/lib/rrd_maintenance.php', true) . ';' .
        '$lock = rrd_maintenance_acquire(' . var_export($exclusiveWait, true) . ', true); echo json_encode($lock === false); rrd_maintenance_release($lock);');
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . dirname(__DIR__, 4), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $pid = proc_get_status($process)['pid'];
    $opened = false; $deadline = microtime(true) + 10;
    try {
        // Wait for the child's own open descriptor, not an inherited parent
        // exclusive lock. No sleep-based assumption decides the race ordering.
        while (!$opened && microtime(true) < $deadline) {
            foreach (glob('/proc/' . $pid . '/fd/*') as $fd) {
                $target = @readlink($fd);
                $info = @file_get_contents('/proc/' . $pid . '/fdinfo/' . basename($fd));
                if ($target === $path && is_string($info) && strpos($info, 'lock:') === false) { $opened = true; break; }
            }
            if (!$opened) { usleep(10000); }
        }
        expect($opened)->toBeTrue();
        rename($path, $this->dir . '/old-store'); mkdir($path, 0700);
    } finally { rrd_maintenance_release($lock); }
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    rmdir($path); rmdir($this->dir . '/old-store');
    expect($status)->toBe(0)->and($stderr)->toBe('')->and($stdout)->toBe('true');
})->with(array(false, true));

test('CLI rewrite locks exclude writers and preserve Windows CLI behavior', function () {
    $lock = rrd_maintenance_cli_lock(true);
    try { expect(is_resource($lock))->toBeTrue()->and(rrd_maintenance_acquire(true))->toBeFalse(); }
    finally { rrd_maintenance_release($lock); }
    $GLOBALS['config']['cacti_server_os'] = 'win32';
    expect(rrd_maintenance_cli_lock(true))->toBeTrue();
});

test('CLI maintenance stops before writing when storage is busy or externally cached', function ($cached) {
    $lock = rrd_maintenance_acquire();
    $script = $this->dir . '/cli-maintenance.php';
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export(dirname(__DIR__, 3) . '/fixtures/rrd-process-coverage.php', true) . ';';
    }
    file_put_contents($script, $bootstrap . '$config = ' . var_export($GLOBALS['config'], true) . ';' .
        'require ' . var_export(dirname(__DIR__, 4) . '/lib/rrd_maintenance.php', true) . ';' .
        ($cached ? 'putenv("RRDCACHED_ADDRESS=unix:/unused/test.sock");' : '') .
        'rrd_maintenance_cli_lock(true); touch(__DIR__ . "/should-not-write");');
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . dirname(__DIR__, 4), '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    try {
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(1)->and($stdout)->toBe('')
            ->and($stderr)->toContain($cached ? 'RRDCACHED_ADDRESS' : 'storage is busy')
            ->and(file_exists($this->dir . '/should-not-write'))->toBeFalse();
    } finally { rrd_maintenance_release($lock); }
})->with(array(false, true));


test('failed acquisitions cannot register an uncoordinated pipe', function () {
    $pipe = fopen('php://temp', 'w+');
    try {
        expect(rrd_maintenance_pipe($pipe, false))->toBeFalse();
        expect(rrd_maintenance_pipe($pipe))->toBeFalse();
    } finally {
        fclose($pipe);
    }
});

test('RRD utility owners release their leases on return and exception', function ($function, $scenario) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required; CI provisions it.');
    }
    $root = dirname(__DIR__, 4);
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$config = array("cacti_server_os" => "unix", "rra_path" => __DIR__, "is_web" => false);' .
        'define("CACTI_LOCALE", "en-US"); define("POLLER_VERBOSITY_DEBUG", 5);' .
        'define("RRDTOOL_OUTPUT_NULL", 0); define("RRDTOOL_OUTPUT_STDOUT", 1); define("RRDTOOL_OUTPUT_GRAPH_DATA", 2); define("RRDTOOL_OUTPUT_STDERR", 3); define("RRDTOOL_OUTPUT_RETURN_STDERR", 4);' .
        'function read_config_option($name) { return $name === "path_rrdtool" ? ' . var_export($binary, true) . ' : ""; }' .
        'function cacti_log(...$args) {} function cacti_session_close() {} function cacti_escapeshellarg($value) { return escapeshellarg($value); }' .
        'function __($message, ...$args) { return $message; }' .
        'function cacti_rrdtool_valid_path($path) { return false; }' .
        'require ' . var_export($root . '/lib/rrd.php', true) . ';' .
        'function failing_files() { throw new RuntimeException("fixture iterator failed"); yield; }' .
        '$files = ' . ($scenario === 'exception' ? 'failing_files()' : ($scenario === 'invalid' ? 'array("../invalid.rrd")' : 'array()')) . ';' .
        '$exception = false; try { $result = ' . $function . '($files, ' . ($function === 'rrd_rra_clone' ? '"AVERAGE", ' : '') . 'array(), false); } catch (Throwable $error) { $exception = true; }' .
        '$lease = rrd_maintenance_acquire(true); $released = is_resource($lease); rrd_maintenance_release($lease);' .
        'file_put_contents(__DIR__ . "/outcome", json_encode(array($released, $exception, $result ?? null)));';
    file_put_contents($this->dir . '/owner.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $this->dir . '/owner.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0);
    $outcome = json_decode(file_get_contents($this->dir . '/outcome'), true);
    expect($outcome[0])->toBeTrue();
    if ($scenario === 'empty') {
        expect($outcome[1])->toBeFalse()->and($outcome[2])->toBeTrue()->and($stderr)->toBe('');
    } elseif ($scenario === 'exception') {
        expect($outcome[1])->toBeTrue()->and($stderr)->toBe('');
    } else {
        expect($outcome[1] || is_array($outcome[2]))->toBeTrue();
    }
})->with(array('rrd_datasource_add', 'rrd_rra_delete', 'rrd_rra_clone'))->with(array('empty', 'invalid', 'exception'));


test('Boost releases a native writer when archive discovery or row selection is empty', function ($emptyArchives) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required; CI provisions it.');
    }
    $root = dirname(__DIR__, 4);
    $source = file_get_contents($root . '/poller_boost.php');
    preg_match('/^function boost_output_rrd_data\(.*?^}\R/ms', $source, $match);
    expect($match)->not->toBeEmpty();
    $bootstrap = '<?php $config = array("cacti_server_os" => "unix", "rra_path" => __DIR__);' .
        'define("CACTI_LOCALE", "en-US"); function cacti_log(...$args) {} function boost_debug(...$args) {}' .
        'function cacti_sizeof($rows) { return count($rows); } function db_fetch_cell_prepared(...$args) { return 0; }' .
        'function cacti_escapeshellarg($value) { return escapeshellarg($value); }' .
        'function read_config_option($name) { return $name === "path_rrdtool" ? ' . var_export($binary, true) . ' : ""; }' .
        'function boost_get_arch_table_names($table) { return ' . ($emptyArchives ? 'array()' : 'array("fixture")') . '; }' .
        '$archive_table = "fixture"; require ' . var_export($root . '/lib/rrd.php', true) . ';' . $match[0] .
        '$result = boost_output_rrd_data(1); $lease = rrd_maintenance_acquire(true);' .
        'file_put_contents(__DIR__ . "/outcome", json_encode(array($result, is_resource($lease)))); rrd_maintenance_release($lease);';
    file_put_contents($this->dir . '/boost-owner.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY, $this->dir . '/boost-owner.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    $outcome = json_decode(file_get_contents($this->dir . '/outcome'), true);
    expect($outcome[0] === false || $outcome[0] === -1)->toBeTrue()->and($outcome[1])->toBeTrue();
})->with(array(true, false));
