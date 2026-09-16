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
    $lock = rrd_maintenance_acquire();
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
    expect($stderr)->toBe('')->and($status)->toBe(0)->and($stdout)->toBe('true');
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


test('unsafe directory permissions refuse both writers and maintenance', function ($mode, $ancestor) {
    mkdir($this->dir . '/store', 0700);
    $GLOBALS['config']['rra_path'] = $this->dir . '/store';
    chmod($ancestor ? $this->dir : $this->dir . '/store', $mode);
    try {
        expect(rrd_maintenance_acquire())->toBeFalse()->and(rrd_maintenance_acquire(true))->toBeFalse();
    } finally {
        chmod($this->dir, 0700); chmod($this->dir . '/store', 0700); rmdir($this->dir . '/store');
    }
})->with([[0770, false], [0777, false], [01777, false], [0770, true], [0777, true]]);

test('trusted children beneath sticky directories and relative aliases remain usable', function () {
    mkdir($this->dir . '/store', 0700); chmod($this->dir, 01777);
    symlink($this->dir . '/store', $this->dir . '/alias');
    $cwd = getcwd(); chdir($this->dir);
    $GLOBALS['config']['rra_path'] = 'alias/';
    $lease = rrd_maintenance_acquire();
    try {
        expect(is_resource($lease))->toBeTrue()->and(rrd_maintenance_acquire(true))->toBeFalse();
        expect(rrd_maintenance_directory_is_trusted(''))->toBeFalse();
        expect(rrd_maintenance_directory_is_trusted('missing'))->toBeFalse();
        file_put_contents('regular', 'data');
        expect(rrd_maintenance_directory_is_trusted('regular'))->toBeFalse();
    } finally {
        rrd_maintenance_release($lease); chdir($cwd); unlink($this->dir . '/alias'); rmdir($this->dir . '/store'); chmod($this->dir, 0700);
    }
});

test('a nested symlink cannot hide an unsafe target ancestor', function () {
    mkdir($this->dir . '/unsafe', 0777); chmod($this->dir . '/unsafe', 0777);
    mkdir($this->dir . '/unsafe/intermediate', 0700); mkdir($this->dir . '/safe', 0700);
    symlink($this->dir . '/safe', $this->dir . '/unsafe/intermediate/target');
    symlink($this->dir . '/unsafe/intermediate', $this->dir . '/alias');
    $GLOBALS['config']['rra_path'] = $this->dir . '/alias/target';
    try { expect(rrd_maintenance_acquire())->toBeFalse(); }
    finally {
        unlink($this->dir . '/alias'); unlink($this->dir . '/unsafe/intermediate/target');
        rmdir($this->dir . '/unsafe/intermediate'); rmdir($this->dir . '/unsafe'); rmdir($this->dir . '/safe');
    }
});

test('untrusted symlink owners are rejected even beneath sticky directories', function () {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) { $this->markTestSkipped('Root is required to assign an adversarial symlink owner.'); }
    mkdir($this->dir . '/store', 0700); chmod($this->dir, 01777);
    symlink($this->dir . '/store', $this->dir . '/alias');
    expect(lchown($this->dir . '/alias', 65534))->toBeTrue();
    $GLOBALS['config']['rra_path'] = $this->dir . '/alias/';
    try { expect(rrd_maintenance_acquire())->toBeFalse(); }
    finally { unlink($this->dir . '/alias'); rmdir($this->dir . '/store'); chmod($this->dir, 0700); }
});

test('another account cannot replace a directory after its lease is acquired', function () {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        $this->markTestSkipped('Root is required for an actual privilege-separated replacement attempt.');
    }
    chmod($this->dir, 0755); mkdir($this->dir . '/store', 0755);
    file_put_contents($this->dir . '/store/source.rrd', 'original samples');
    $GLOBALS['config']['rra_path'] = $this->dir . '/store';
    $lease = rrd_maintenance_acquire();
    try {
        expect(is_resource($lease))->toBeTrue();
        // A separate interpreter must not inherit the test runner's shutdown
        // callbacks: those can require privileges dropped by this adversary.
        $script = '$dir = ' . var_export($this->dir, true) . ';' .
            'if (!posix_setgid(65534) || !posix_setuid(65534)) { exit(2); }' .
            '$renamed = @rename($dir . "/store", $dir . "/old");' .
            '$replaced = @mkdir($dir . "/store", 0755);' .
            'exit($renamed || $replaced ? 1 : 0);';
        $process = proc_open(array(PHP_BINARY, '-r', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        expect(is_resource($process))->toBeTrue();
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stdout)->toBe('')->and($stderr)->toBe('');
        expect(file_get_contents($this->dir . '/store/source.rrd'))->toBe('original samples');
        expect(rrd_maintenance_acquire(true))->toBeFalse();
    } finally {
        rrd_maintenance_release($lease); unlink($this->dir . '/store/source.rrd'); rmdir($this->dir . '/store');
    }
});


test('failed writer initialization preserves normal and Boost queues before any database access', function ($mode) {
    $root = dirname(__DIR__, 4);
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $source = file_get_contents($root . '/poller_boost.php');
    preg_match('/^function boost_output_rrd_data\(.*?^}\R/ms', $source, $match);
    expect($match)->not->toBeEmpty();
    file_put_contents($this->dir . '/source.rrd', 'retained samples');
    if ($mode === 'untrusted') { chmod($this->dir, 0777); }
    $bootstrap .= '$config = ' . var_export(array('cacti_server_os' => 'unix', 'rra_path' => $this->dir . ($mode === 'missing' ? '/missing' : ''), 'library_path' => $root . '/lib'), true) . ';' .
        'function read_config_option($name) { return ""; } function cacti_log(...$args) {} function cacti_system_zone_set() {}' .
        'function db_fetch_assoc(...$args) { throw new RuntimeException("queue read after failed initialization"); }' .
        'function db_fetch_assoc_prepared(...$args) { return db_fetch_assoc(...$args); }' .
        'function db_fetch_cell(...$args) { return db_fetch_assoc(...$args); }' .
        'function db_execute(...$args) { throw new RuntimeException("queue mutation after failed initialization"); }' .
        'function db_execute_prepared(...$args) { return db_execute(...$args); }' .
        'require ' . var_export($root . '/lib/rrd.php', true) . ';' .
        'require ' . var_export($root . '/lib/poller.php', true) . ';' .
        'require ' . var_export($root . '/lib/boost.php', true) . ';' . $match[0] .
        '$pipe = rrd_init(); $normal = process_poller_output($pipe);' .
        '$daemon = boost_output_rrd_data(1); $ondemand = boost_process_poller_output(1);' .
        'echo json_encode(array($pipe, $normal, $daemon, $ondemand, file_get_contents(__DIR__ . "/source.rrd")));';
    file_put_contents($this->dir . '/queue-init.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $this->dir . '/queue-init.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process); chmod($this->dir, 0700);
    expect($stderr)->toBe('')->and($status)->toBe(0, $stdout)
        ->and(json_decode($stdout, true))->toBe(array(false, 0, -1, -1, 'retained samples'));
})->with(array('missing', 'untrusted'));

test('utility rewrite ownership excludes shared writers and refuses busy or cached storage', function ($mode) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) { $this->markTestSkipped('Real RRDtool is required; CI provisions it.'); }
    $root = dirname(__DIR__, 4);
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$config = array("cacti_server_os" => "unix", "rra_path" => __DIR__, "is_web" => false); define("CACTI_LOCALE", "en-US");' .
        'function read_config_option($name) { return $name === "path_rrdtool" ? ' . var_export($binary, true) . ' : ""; }' .
        'function cacti_log(...$args) {} function cacti_escapeshellarg($value) { return escapeshellarg($value); } require ' . var_export($root . '/lib/rrd.php', true) . ';' .
        'if (' . var_export($mode === 'cached', true) . ') { putenv("RRDCACHED_ADDRESS=unix:/unavailable-test-cache"); }' .
        '$called = false; $exclusive = false; $result = rrd_with_pipe(function ($pipe) use (&$called, &$exclusive) {' .
        '$called = true; $probe = fopen(__DIR__, "r"); $exclusive = !flock($probe, LOCK_SH | LOCK_NB); fclose($probe); return true; });' .
        'echo json_encode(array($called, $exclusive, $result));';
    file_put_contents($this->dir . '/utility-lock.php', $bootstrap);
    $writer = $mode === 'busy' ? rrd_maintenance_acquire() : null;
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $this->dir . '/utility-lock.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('')
            ->and(json_decode($stdout, true))->toBe($mode === 'available' ? array(true, true, true) : array(false, false, false));
    } finally { rrd_maintenance_release($writer); }
})->with(array('available', 'busy', 'cached'));


test('on-demand Boost restores caller state and closes only its own writer after an exception', function ($supplied) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) { $this->markTestSkipped('Real RRDtool is required; CI provisions it.'); }
    $root = dirname(__DIR__, 4); $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$config = array("cacti_server_os" => "unix", "rra_path" => __DIR__, "library_path" => ' . var_export($root . '/lib', true) . '); define("CACTI_LOCALE", "en-US");' .
        'function read_config_option($name) { return $name === "path_rrdtool" ? ' . var_export($binary, true) . ' : ""; }' .
        'function cacti_escapeshellarg($value) { return escapeshellarg($value); } function cacti_log(...$args) {} function cacti_system_zone_set() {}' .
        'function db_fetch_assoc(...$args) { throw new RuntimeException("fixture query failed"); }' .
        'require ' . var_export($root . '/lib/rrd.php', true) . '; require ' . var_export($root . '/lib/boost.php', true) . ';' .
        '$pipe = ' . ($supplied ? 'rrd_init()' : 'false') . '; $handler = function () { return true; }; set_error_handler($handler); error_reporting(E_ALL);' .
        '$caught = false; try { boost_process_poller_output(1, $pipe); } catch (RuntimeException $e) { $caught = $e->getMessage() === "fixture query failed"; }' .
        '$level = error_reporting() === E_ALL; $restored = set_error_handler($handler) === $handler; restore_error_handler(); restore_error_handler();' .
        '$lease = rrd_maintenance_acquire(true); $available = is_resource($lease); rrd_maintenance_release($lease);' .
        'if (is_resource($pipe)) { rrd_close($pipe); } $lease = rrd_maintenance_acquire(true);' .
        'echo json_encode(array($caught, $restored, $level, $available, is_resource($lease))); rrd_maintenance_release($lease);';
    file_put_contents($this->dir . '/ondemand-owner.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $this->dir . '/ondemand-owner.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('')->and(json_decode($stdout, true))->toBe(array(true, true, true, !$supplied, true));
})->with(array(false, true));

test('a broken exclusive rewrite pipe aborts without retrying a stale snapshot', function () {
    if (!function_exists('pcntl_waitpid')) { $this->markTestSkipped('pcntl is required to synchronize a real broken pipe.'); }
    $root = dirname(__DIR__, 4); $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    file_put_contents($this->dir . '/source.rrd', 'retained samples');
    $bootstrap .= '$config = array("cacti_server_os" => "unix", "rra_path" => __DIR__);' .
        'define("RRDTOOL_OUTPUT_STDOUT",1); define("RRDTOOL_OUTPUT_STDERR",3); define("RRDTOOL_OUTPUT_RETURN_STDERR",4); define("POLLER_VERBOSITY_DEBUG",5);' .
        'function read_config_option($name) { return ""; } function cacti_log(...$args) {}' .
        'require ' . var_export($root . '/lib/rrd.php', true) . '; require_once ' . var_export($root . '/lib/rrd_maintenance.php', true) . ';' .
        '$pipe = popen("exec false", "w"); if (pcntl_waitpid(-1, $status) < 1) { exit(2); }' .
        'rrd_maintenance_pipe($pipe, rrd_maintenance_acquire(true), false, true); set_error_handler(function () { return true; });' .
        '$caught = false; try { rrdtool_execute("update " . __DIR__ . "/source.rrd 1000000060:42", false, 1, $pipe); }' .
        'catch (RuntimeException $e) { $caught = strpos($e->getMessage(), "aborted without retry") !== false; } finally { restore_error_handler(); }' .
        '$lease = rrd_maintenance_acquire(true); echo json_encode(array($caught, is_resource($lease), rrd_maintenance_pipe_is_exclusive($pipe), file_get_contents(__DIR__ . "/source.rrd"))); rrd_maintenance_release($lease);';
    file_put_contents($this->dir . '/exclusive-failure.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $this->dir . '/exclusive-failure.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('')
        ->and(json_decode($stdout, true))->toBe(array(true, true, false, 'retained samples'));
});

test('a failed realtime writer keeps its queued samples and reports the failure', function ($mode) {
    $root = dirname(__DIR__, 4);
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $source = file_get_contents($root . '/poller_realtime.php');
    preg_match('/^function process_poller_output_rt\(.*?^}\R/ms', $source, $match);
    expect($match)->not->toBeEmpty();
    if ($mode === 'untrusted') {
        chmod($this->dir, 0777);
    }
    $bootstrap .= '$logged = array(); $config = ' . var_export(array('cacti_server_os' => 'unix', 'rra_path' => $this->dir . ($mode === 'missing' ? '/missing' : ''), 'library_path' => $root . '/lib'), true) . ';' .
        'function read_config_option($name) { return ""; } function cacti_log($message, ...$args) { $GLOBALS["logged"][] = $message; }' .
        'function db_fetch_assoc_prepared(...$args) { throw new RuntimeException("realtime queue read after failed initialization"); }' .
        'function db_execute_prepared(...$args) { throw new RuntimeException("realtime queue mutation after failed initialization"); }' .
        'require ' . var_export($root . '/lib/rrd.php', true) . ';' . $match[0] .
        '$pipe = rrd_init(); $processed = process_poller_output_rt($pipe, "abcd", 1);' .
        'echo json_encode(array($pipe, $processed, $GLOBALS["logged"]));';
    file_put_contents($this->dir . '/realtime-init.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $this->dir . '/realtime-init.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    chmod($this->dir, 0700);
    $result = json_decode($stdout, true);
    expect($stderr)->toBe('')->and($status)->toBe(0)
        ->and(array($result[0], $result[1]))->toBe(array(false, false))
        ->and($result[2])->toContain('ERROR: RRD initialization failed; pending realtime samples were retained.');
})->with(array('missing', 'untrusted'));



test('explicit storage group trust permits shared accounts but never world write', function () {
    chmod($this->dir, 0770);
    expect(rrd_maintenance_acquire())->toBeFalse();
    $GLOBALS['config']['rrd_maintenance_trusted_gids'] = array(filegroup($this->dir));
    $lock = rrd_maintenance_acquire();
    try {
        expect(is_resource($lock))->toBeTrue()->and(rrd_maintenance_acquire(true))->toBeFalse();
    } finally {
        rrd_maintenance_release($lock);
    }
    chmod($this->dir, 0777);
    expect(rrd_maintenance_acquire())->toBeFalse();
    chmod($this->dir, 0700);
});

test('malformed administrator trust lists fail closed', function ($uids, $gids) {
    $GLOBALS['config']['rrd_maintenance_trusted_uids'] = $uids;
    $GLOBALS['config']['rrd_maintenance_trusted_gids'] = $gids;
    expect(rrd_maintenance_acquire())->toBeFalse();
})->with(array(array('33', array()), array(array(), '33'), array(array(-1), array()), array(array(), array('33')), array(array(true), array())));

test('a separate configured storage owner is trusted only explicitly', function () {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        $this->markTestSkipped('Root is required to exercise a separate service UID; CI runs the privileged suite.');
    }
    try {
        expect(chown($this->dir, 65534))->toBeTrue();
        chmod($this->dir, 0755);
        expect(rrd_maintenance_acquire())->toBeFalse();
        $GLOBALS['config']['rrd_maintenance_trusted_uids'] = array(65534);
        $lock = rrd_maintenance_acquire();
        try {
            expect(is_resource($lock))->toBeTrue();
        } finally {
            rrd_maintenance_release($lock);
        }
    } finally {
        chown($this->dir, 0);
        chmod($this->dir, 0700);
    }
});


test('a separate web UID can coordinate a poller-owned shared store', function () {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        $this->markTestSkipped('Root is required to switch service identities; CI runs this privileged case.');
    }
    $script = '<?php require ' . var_export(dirname(__DIR__, 4) . '/lib/rrd_maintenance.php', true) . ';' .
        'if (!posix_setgid(65533) || !posix_setuid(65533)) { exit(2); }' .
        '$config = array("cacti_server_os" => "unix", "rra_path" => __DIR__);' .
        '$untrusted = rrd_maintenance_acquire();' .
        '$config["rrd_maintenance_trusted_uids"] = array(65534);' .
        '$untrusted_group = rrd_maintenance_acquire();' .
        '$config["rrd_maintenance_trusted_gids"] = array(65533);' .
        '$lock = rrd_maintenance_acquire();' .
        'echo json_encode(array($untrusted, $untrusted_group, is_resource($lock))); rrd_maintenance_release($lock);';
    file_put_contents($this->dir . '/web-user.php', $script);
    try {
        expect(chown($this->dir, 65534))->toBeTrue()->and(chgrp($this->dir, 65533))->toBeTrue();
        chmod($this->dir, 0770);
        $process = proc_open(array(PHP_BINARY, $this->dir . '/web-user.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('')
            ->and(json_decode($output, true))->toBe(array(false, false, true));
    } finally {
        chown($this->dir, 0);
        chgrp($this->dir, 0);
        chmod($this->dir, 0700);
    }
});

test('queued samples require an actual RRDtool acknowledgement', function ($mode, $expected, $persistent) {
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
    $validation = '';
    $functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
    foreach (array('cacti_has_control_chars','cacti_rrdtool_valid_path','cacti_rrdtool_valid_ds_template','cacti_rrdtool_valid_ds_name') as $function) {
        $validation .= test_php_function_source($functions, $function);
    }
    $root = dirname(__DIR__, 4);
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: '/opt/homebrew/bin/rrdtool';
    if (!is_executable($binary)) { $this->markTestSkipped('Real RRDtool is required.'); }
    $rrd = $this->dir . '/sample.rrd';
    exec(escapeshellarg($binary) . ' create ' . escapeshellarg($rrd) . ' --start 1700000000 --step 60 DS:value:GAUGE:600:U:U RRA:AVERAGE:0.5:1:20', $out, $status);
    expect($status)->toBe(0);
    $wrapper = $this->dir . '/rrd-wrapper';
    $error_responses = array('error' => "ERROR: failed update\n", 'permission' => "ERROR: opening 'x.rrd': Permission denied\n", 'disk' => "ERROR: No space left on device\n", 'missing' => "ERROR: opening 'x.rrd': No such file or directory\n");
    $response = in_array($mode, array('silent', 'crash', 'hung'), true) ? '' : ($error_responses[$mode] ?? "OK u:0 s:0 r:0\n");
    if ($mode === 'real') {
        file_put_contents($wrapper, "#!/bin/sh\nexec " . escapeshellarg($binary) . " \"\$@\"\n");
    } else {
        file_put_contents($wrapper, '#!' . PHP_BINARY . "\n<?php fgets(STDIN); echo " . var_export($response, true) . '; ' . ($mode === 'hung' ? 'sleep(30);' : '') . 'exit(' . ($mode === 'crash' ? 9 : 0) . ');');
    }
    chmod($wrapper, 0700);
    $bootstrap = '<?php $config = ' . var_export(array('cacti_server_os' => 'unix', 'rra_path' => $this->dir, 'is_web' => false, 'rrd_command_timeout' => 1), true) . ';' .
        'define("CACTI_LOCALE","en-US"); define("POLLER_VERBOSITY_DEBUG",5);' .
        'define("RRDTOOL_OUTPUT_STDOUT",1); define("RRDTOOL_OUTPUT_STDERR",2); define("RRDTOOL_OUTPUT_GRAPH_DATA",3); define("RRDTOOL_OUTPUT_BOOLEAN",4); define("RRDTOOL_OUTPUT_RETURN_STDERR",5);' .
        'function read_config_option($key) { return $key === "path_rrdtool" ? ' . var_export($wrapper, true) . ' : ""; }' .
        'function cacti_log(...$args) {} function cacti_session_close() {} function cacti_sizeof($v) {return count($v);}' .
        'function cacti_escapeshellarg($value){return escapeshellarg($value);} function get_rrdtool_version(){return "1.7";} function cacti_version_compare(...$args){return version_compare(...$args);}' . $validation .
        'require ' . var_export($root . '/lib/rrd.php', true) . ';' .
        '$updates = array(' . var_export($rrd, true) . ' => array("local_data_id"=>1,"data_template_id"=>0,"times"=>array(1700000060=>array("value"=>42))));' .
        '$pipe=' . ($persistent ? 'rrd_init(false,false,true)' : 'false') . '; if (' . var_export($persistent,true) . ' && !is_resource($pipe)) {exit(2);} $result=rrdtool_function_update($updates,$pipe,$completed); echo json_encode(array($result,!empty($completed))); if($result===false){if(rrdtool_function_update($updates,$pipe)!==false){exit(3);}} rrd_close($pipe);';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap = '<?php define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';' . substr($bootstrap, 5);
    }
    file_put_contents($this->dir . '/ack.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $this->dir . '/ack.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if ($error !== '') { throw new RuntimeException($error . $output); }
    expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('')->and(json_decode($output,true))->toBe(array($expected, $mode === 'real'));
    if ($mode === 'real') { expect(shell_exec(escapeshellarg($binary) . ' lastupdate ' . escapeshellarg($rrd)))->toContain('42'); }
})->with(array(array('real',1), array('silent',false), array('error',false), array('permission',false), array('disk',false), array('missing',false), array('crash',false), array('hung',false)))->with(array(true,false));

test('one persistent process consumes explicit rejects and continues subsequent timestamps', function ($web) {
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
    $validation = '';
    $functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
    foreach (array('cacti_has_control_chars','cacti_rrdtool_valid_path','cacti_rrdtool_valid_ds_template','cacti_rrdtool_valid_ds_name') as $function) {
        $validation .= test_php_function_source($functions, $function);
    }
    $root = dirname(__DIR__, 4);
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: '/opt/homebrew/bin/rrdtool';
    if (!is_executable($binary)) { $this->markTestSkipped('Real RRDtool is required.'); }
    foreach (array('bad', 'good') as $name) {
        exec(escapeshellarg($binary) . ' create ' . escapeshellarg($this->dir . '/' . $name . '.rrd') . ' --start 1700000000 --step 60 DS:value:GAUGE:600:U:U RRA:AVERAGE:0.5:1:200', $out, $status);
        expect($status)->toBe(0);
    }
    $wrapper = $this->dir . '/counting-rrd';
    file_put_contents($wrapper, "#!/bin/sh\necho started >> " . escapeshellarg($this->dir . '/children') . "\nexec " . escapeshellarg($binary) . ' "$@"' . "\n");
    chmod($wrapper, 0700);
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$config=' . var_export(array('cacti_server_os' => 'unix', 'rra_path' => $this->dir, 'is_web' => $web, 'rrd_command_timeout' => 1), true) . ';' .
        'define("CACTI_LOCALE","en-US");define("POLLER_VERBOSITY_DEBUG",5);' .
        'define("RRDTOOL_OUTPUT_NULL",0);define("RRDTOOL_OUTPUT_STDOUT",1);define("RRDTOOL_OUTPUT_STDERR",2);define("RRDTOOL_OUTPUT_GRAPH_DATA",3);define("RRDTOOL_OUTPUT_BOOLEAN",4);define("RRDTOOL_OUTPUT_RETURN_STDERR",5);' .
        'function read_config_option($key){return $key==="path_rrdtool"?' . var_export($wrapper,true) . ':"";}' .
        'function cacti_log(...$args){}function cacti_session_close(){}function cacti_sizeof($v){return count($v);}' .
        'function get_rrdtool_version(){return "1.7";}function cacti_version_compare(...$args){return version_compare(...$args);}' .
        'function cacti_escapeshellarg($v){return escapeshellarg($v);}' . $validation . 'require ' . var_export($root . '/lib/rrd.php',true) . ';' .
        '$bad=__DIR__."/bad.rrd";$good=__DIR__."/good.rrd";' .
        '$updates=array($bad=>array("local_data_id"=>1,"data_template_id"=>0,"times"=>array(1700000060=>array("missing"=>42),1700000120=>array("value"=>43))),$good=>array("local_data_id"=>2,"data_template_id"=>0,"times"=>array()));' .
        'for($i=1;$i<=100;$i++){$updates[$good]["times"][1700000000+$i*60]=array("value"=>$i);}' .
        '$pipe=rrd_init(' . var_export($web,true) . ',false,true);$command="create ".__DIR__."/created.rrd --start 1700000000 --step 60".RRD_NL."DS:value:GAUGE:600:U:U".RRD_NL."RRA:AVERAGE:0.5:1:20";if(rrdtool_execute($command,false,RRDTOOL_OUTPUT_BOOLEAN,$pipe)!==true){exit(6);}$result=rrdtool_function_update($updates,$pipe,$completed);rrd_close($pipe);' .
        'echo json_encode(array($result,count($completed[$bad]),count($completed[$good]),count(rrd_acknowledged_pipes())));';
    file_put_contents($this->dir . '/persistent.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $this->dir . '/persistent.php'), array(1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $status=proc_close($process);
    if ($error !== '') { throw new RuntimeException($error . $output); }
    expect($status)->toBe(0)->and(json_decode($output,true))->toBe(array(false,2,100,0));
    expect(file($this->dir . '/children'))->toHaveCount(1);
    expect(trim(shell_exec(escapeshellarg($binary) . ' last ' . escapeshellarg($this->dir . '/bad.rrd'))))->toBe('1700000120');
    expect(trim(shell_exec(escapeshellarg($binary) . ' last ' . escapeshellarg($this->dir . '/good.rrd'))))->toBe('1700006000');
})->with(array(false, true));

test('shared writer lock contention has a bounded deadline', function () {
    $lease = rrd_maintenance_acquire(true);
    $start = hrtime(true);
    try {
        expect(rrd_maintenance_acquire(false, true, 0.1))->toBeFalse();
        expect((hrtime(true) - $start) / 1000000000)->toBeLessThan(2.0);
    } finally { rrd_maintenance_release($lease); }
});

test('actual RRD utilities rewrite valid files and release their exclusive lease', function ($function, $debug) {
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
    $validation = '';
    $functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
    foreach (array('cacti_has_control_chars','cacti_rrdtool_valid_path','cacti_rrdtool_valid_path_token','cacti_rrdtool_valid_ds_template','cacti_rrdtool_valid_ds_name') as $name) {
        $validation .= test_php_function_source($functions, $name);
    }
    $root = dirname(__DIR__, 4);
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: '/opt/homebrew/bin/rrdtool';
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required.');
    }
    $rrd = $this->dir . '/utility.rrd';
    exec(escapeshellarg($binary) . ' create ' . escapeshellarg($rrd) . ' --start 1700000000 --step 60 DS:value:GAUGE:600:U:U RRA:AVERAGE:0.5:1:20 RRA:MAX:0.5:1:20', $out, $status);
    expect($status)->toBe(0);
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$config=array("cacti_server_os"=>"unix","rra_path"=>__DIR__,"is_web"=>false);$data_source_types=array(5=>"COMPUTE");' .
        'define("RRD_FILE_VERSION1","0001");define("RRD_FILE_VERSION3","0003");define("CACTI_LOCALE","en-US");define("POLLER_VERBOSITY_DEBUG",5);' .
        'define("RRDTOOL_OUTPUT_NULL",0);define("RRDTOOL_OUTPUT_STDOUT",1);define("RRDTOOL_OUTPUT_STDERR",2);define("RRDTOOL_OUTPUT_GRAPH_DATA",3);define("RRDTOOL_OUTPUT_BOOLEAN",4);define("RRDTOOL_OUTPUT_RETURN_STDERR",5);' .
        'function read_config_option($k){return $k==="path_rrdtool"?' . var_export($binary, true) . ':"";}' .
        'function cacti_log(...$a){}function cacti_session_close(){}function __($message,...$args){return $message;}function cacti_escapeshellarg($v){return escapeshellarg($v);}' .
        $validation . 'require ' . var_export($root . '/lib/rrd.php', true) . ';';
    $params = $function === 'rrd_datasource_add' ? array(array('name' => 'added','type' => 'GAUGE','heartbeat' => 600,'min' => 0,'max' => 100)) : array(array('cf' => 'AVERAGE','pdp_per_row' => 1,'xff' => 0.5,'rows' => 20));
    $bootstrap .= '$result=' . $function . '(array(' . var_export($rrd, true) . '),' . ($function === 'rrd_rra_clone' ? '"MIN",' : '') . var_export($params, true) . ',' . var_export($debug, true) . ');' .
        '$lease=rrd_maintenance_acquire(true);file_put_contents(__DIR__."/result",json_encode(array($result,is_resource($lease))));rrd_maintenance_release($lease);';
    file_put_contents($this->dir . '/utility.php', $bootstrap);
    $process = proc_open(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~',$this->dir . '/utility.php'), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($error !== '') {
        throw new RuntimeException($error);
    }
    expect($status)->toBe(0)->and(json_decode(file_get_contents($this->dir . '/result'), true))->toBe(array(true,true));
    $dump = shell_exec(escapeshellarg($binary) . ' dump ' . escapeshellarg($rrd));
    $xml = simplexml_load_string($dump);
    expect(count($xml->ds))->toBe(!$debug && $function === 'rrd_datasource_add' ? 2 : 1);
    expect(count($xml->rra))->toBe($debug || $function === 'rrd_datasource_add' ? 2 : ($function === 'rrd_rra_delete' ? 1 : 3));
    if ($debug) {
        expect($output)->toContain('<rrd>');
    }
})->with(array('rrd_datasource_add','rrd_rra_delete','rrd_rra_clone'))->with(array(false,true));


test('read-only RRDtool commands remain available during maintenance and with group writable storage', function ($verb, $arguments) {
    $root = dirname(__DIR__, 4);
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: '/opt/homebrew/bin/rrdtool';
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required.');
    }
    exec(escapeshellarg($binary) . ' create ' . escapeshellarg($this->dir . '/read.rrd') . ' --start 1700000000 --step 60 DS:value:GAUGE:600:U:U RRA:AVERAGE:0.5:1:200', $out, $status);
    expect($status)->toBe(0);
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $command = $verb . ' ' . str_replace('{rrd}', $this->dir . '/read.rrd', $arguments);
    $bootstrap .= '$config=' . var_export(array('cacti_server_os' => 'unix', 'rra_path' => $this->dir, 'is_web' => false), true) . ';' .
        'define("CACTI_LOCALE","en-US");define("RRDTOOL_OUTPUT_BOOLEAN",4);' .
        'function read_config_option($key){return $key==="path_rrdtool"?' . var_export($binary, true) . ':"";}' .
        'function cacti_log(...$args){}function cacti_session_close(){}' .
        'require ' . var_export($root . '/lib/rrd.php', true) . ';' .
        'echo json_encode(rrdtool_execute(' . var_export($command, true) . ',false,RRDTOOL_OUTPUT_BOOLEAN));';
    file_put_contents($this->dir . '/reader.php', $bootstrap);
    $lease = rrd_maintenance_acquire(true);
    expect(is_resource($lease))->toBeTrue();
    chmod($this->dir, 0777);
    try {
        $process = proc_open(array(PHP_BINARY, $this->dir . '/reader.php'), array(1 => array('pipe','w'), 2 => array('pipe','w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('')->and(json_decode($output,true))->toBeTrue();
    } finally {
        chmod($this->dir, 0700);
        rrd_maintenance_release($lease);
    }
})->with(array(
    array('info', '{rrd}'), array('last', '{rrd}'), array('first', '{rrd}'), array('lastupdate', '{rrd}'),
    array('fetch', '{rrd} AVERAGE --start 1700000000 --end 1700000120'),
    array('graph', '/dev/null --start 1700000000 --end 1700000120 DEF:v={rrd}:value:AVERAGE LINE1:v#FF0000'),
    array('graphv', '/dev/null --start 1700000000 --end 1700000120 DEF:v={rrd}:value:AVERAGE LINE1:v#FF0000'),
    array('xport', '--start 1700000000 --end 1700000120 DEF:v={rrd}:value:AVERAGE XPORT:v:value'),
));


test('a timed out restore preserves the live RRD and recovery XML', function () {
    $root = dirname(__DIR__, 4);
    file_put_contents($this->dir . '/live.rrd', 'original data');
    file_put_contents($this->dir . '/recovery.xml', 'recovery data');
    $wrapper = $this->dir . '/slow-restore';
    file_put_contents($wrapper, '#!' . PHP_BINARY . "\n<?php $" . 'line=fgets(STDIN); $args=str_getcsv(trim($line)," ","\'"); file_put_contents(end($args),"partial restore"); sleep(30);');
    chmod($wrapper, 0700);
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$config=' . var_export(array('cacti_server_os' => 'unix', 'rra_path' => $this->dir, 'is_web' => false, 'rrd_command_timeout' => 1), true) . ';' .
        'define("CACTI_LOCALE","en-US");define("RRDTOOL_OUTPUT_BOOLEAN",4);' .
        'function cacti_escapeshellarg($v){return escapeshellarg($v);}' .
        'function read_config_option($key){return $key==="path_rrdtool"?' . var_export($wrapper,true) . ':"";}' .
        'function cacti_log($msg,...$args){file_put_contents(__DIR__."/messages",$msg."\n",FILE_APPEND);}function cacti_session_close(){}' .
        'require ' . var_export($root . '/lib/rrd.php', true) . ';' .
        '$pipe=rrd_init(false,true,true);$result=rrd_maintenance_restore(__DIR__."/recovery.xml",__DIR__."/live.rrd",$pipe);rrd_close($pipe);echo json_encode($result);';
    file_put_contents($this->dir . '/restore.php',$bootstrap);
    $process=proc_open(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~',$this->dir.'/restore.php'),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('')->and($output)->toBe('false');
    expect(file_get_contents($this->dir.'/live.rrd'))->toBe('original data');
    expect(file_get_contents($this->dir.'/recovery.xml'))->toBe('recovery data');
    expect(file_get_contents($this->dir.'/messages'))->toContain('original preserved')->toContain($this->dir.'/recovery.xml');
    expect(glob($this->dir.'/.rrd-restore-*'))->toBe(array());
});


test('only deterministic sample errors are consumed while storage failures remain retryable', function () {
    $root = dirname(__DIR__, 4);
    $reasons = array(null, '', 'failed update', 'opening sample.rrd: Permission denied', 'No space left on device', 'rrdcached: connection refused', 'unknown DS name \'missing\'', 'expected 2 data source readings (got 1) from 123:4', 'illegal attempt to update using time 123 when last update time is 124 (minimum one second step)', 'io error containing unknown DS name \'missing\'');
    $bootstrap = '<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'function read_config_option($key){return "";} require ' . var_export($root . '/lib/rrd.php',true) . '; echo json_encode(array_map("rrdtool_rejection_is_permanent",' . var_export($reasons,true) . '));';
    file_put_contents($this->dir.'/classify.php',$bootstrap);
    $process=proc_open(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~',$this->dir.'/classify.php'),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($error)->toBe('');
    expect(json_decode($output,true))->toBe(array(false,false,false,false,false,false,true,true,true,false));
});


test('restore preflight preserves originals and logs unavailable ownership or storage', function ($mode) {
    if (in_array($mode, array('directory','file'), true) && posix_geteuid() === 0) {
        $this->markTestSkipped('Root bypasses ordinary write permissions.');
    }
    $root = dirname(__DIR__, 4);
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) { $this->markTestSkipped('Real RRDtool is required.'); }
    file_put_contents($this->dir.'/live.rrd','original data');
    file_put_contents($this->dir.'/recovery.xml','recovery data');
    touch($this->dir.'/messages');
    $bootstrap='<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports = 1;
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap.='$config='.var_export(array('cacti_server_os'=>'unix','rra_path'=>$this->dir),true).';'.
        'define("CACTI_LOCALE","en-US");function cacti_session_close(){}function read_config_option($key){return $key==="path_rrdtool"?'.var_export($binary,true).':"";}'.
        'function cacti_log($msg,...$args){file_put_contents(__DIR__."/messages",$msg."\n",FILE_APPEND);}'.
        'require '.var_export($root.'/lib/rrd.php',true).';$pipe=rrd_init(false,true,true);$file=__DIR__."/live.rrd";';
    if ($mode === 'directory') { $bootstrap .= 'chmod(__DIR__,0500);'; }
    if ($mode === 'file') { $bootstrap .= 'chmod($file,0400);'; }
    if ($mode === 'symlink') { $bootstrap .= 'symlink($file,__DIR__."/alias.rrd");$file=__DIR__."/alias.rrd";'; }
    $bootstrap .= '$result=rrd_maintenance_restore(__DIR__."/recovery.xml",$file,'.($mode === 'lease' ? 'false' : '$pipe').');chmod(__DIR__,0700);rrd_close($pipe);echo json_encode($result);';
    file_put_contents($this->dir.'/preflight.php',$bootstrap);
    $process=proc_open(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~',$this->dir.'/preflight.php'),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);chmod($this->dir,0700);
    expect(proc_close($process))->toBe(0,$error)->and($error)->toBe('')->and($output)->toBe('false');
    expect(file_get_contents($this->dir.'/live.rrd'))->toBe('original data');
    expect(file_get_contents($this->dir.'/messages'))->toContain(in_array($mode,array('file','directory'),true)?'writable storage directory and file':'exclusive lease and safe regular-file paths');
    expect(glob($this->dir.'/.rrd-restore-*'))->toBe(array());
})->with(array('lease','symlink','directory','file'));


test('poller storage preflight notifies administrators and fails closed on untrusted shared stores', function ($mode) {
    if ($mode === 'readonly' && posix_geteuid() === 0) { $this->markTestSkipped('Root bypasses write permissions.'); }
    $root = dirname(__DIR__, 4);
    $configuration = array('cacti_server_os'=>'unix','rra_path'=>$this->dir);
    if ($mode !== 'private') { chmod($this->dir,0770); }
    if ($mode === 'trusted-group') { $configuration['rrd_maintenance_trusted_gids']=array(posix_getegid()); }
    $blocked = in_array($mode,array('untrusted-group','readonly'),true);
    $bootstrap='<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports=1;
        $bootstrap.='define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require '.var_export($root.'/tests/fixtures/rrd-process-coverage.php',true).';';
    }
    $bootstrap.='$config='.var_export($configuration,true).';$messages=array();$notifications=array();'.
        'function read_config_option($key){return false;}function __($message){return $message;}'.
        'function cacti_log($message,...$args){$GLOBALS["messages"][]=$message;}'.
        'function admin_email($subject,$message){$GLOBALS["notifications"][]=array($subject,$message);}'.
        'require '.var_export($root.'/lib/rrd_maintenance.php',true).';'.
        '$result=rrd_maintenance_poller_preflight();chmod(__DIR__,0700);echo json_encode(array($result,$messages,$notifications));';
    file_put_contents($this->dir.'/preflight.php',$bootstrap);
    if ($mode === 'readonly') { chmod($this->dir,0555); }
    $process=proc_open(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~',$this->dir.'/preflight.php'),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);chmod($this->dir,0700);
    expect(proc_close($process))->toBe(0,$error)->and($error)->toBe('');
    $result=json_decode($output,true);
    expect($result[0])->toBe(!$blocked);
    expect($result[1])->toHaveCount($blocked?1:0);
    expect($result[2])->toHaveCount($blocked?1:0);
    if ($blocked) { expect($result[2][0][1])->toContain('rrd_maintenance_trusted_uids')->toContain('rrd_maintenance_trusted_gids'); }
})->with(array('private','trusted-group','untrusted-group','readonly'));


test('destructive commands cannot use a shared writer lease', function ($verb, $persistent) {
    $root=dirname(__DIR__,4);
    $wrapper=$this->dir.'/rrd-writer';
    file_put_contents($wrapper,'#!'.PHP_BINARY."\n<?php if(fgets(STDIN)!==false){file_put_contents(__DIR__.\"/executed\",\"yes\");echo \"OK u:0 s:0 r:0\\n\";}");chmod($wrapper,0700);
    $bootstrap='<?php ';
    if ($this->getTestResultObject()->getCodeCoverage() !== null) {
        $this->expectedChildReports=1;
        $bootstrap.='define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require '.var_export($root.'/tests/fixtures/rrd-process-coverage.php',true).';';
    }
    $bootstrap.='$config='.var_export(array('cacti_server_os'=>'unix','rra_path'=>$this->dir),true).';'.
        'define("CACTI_LOCALE","en-US");define("RRDTOOL_OUTPUT_BOOLEAN",4);function cacti_session_close(){}function cacti_log(...$args){}'.
        'function read_config_option($key){return $key==="path_rrdtool"?'.var_export($wrapper,true).':"";}'.
        'require '.var_export($root.'/lib/rrd.php',true).';$pipe='.($persistent?'rrd_init(false,false,true)':'false').';'.
        '$result=rrdtool_execute('.var_export($verb.' fixture.rrd',true).',false,RRDTOOL_OUTPUT_BOOLEAN,$pipe);rrd_close($pipe);echo json_encode($result);';
    file_put_contents($this->dir.'/destructive.php',$bootstrap);
    $lease=rrd_maintenance_acquire();
    try {
        $process=proc_open(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~',$this->dir.'/destructive.php'),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        expect(proc_close($process))->toBe(0,$error)->and($error)->toBe('')->and($output)->toBe('false');
        expect(file_exists($this->dir.'/executed'))->toBeFalse();
    } finally { rrd_maintenance_release($lease); }
})->with(array('tune','resize','restore'))->with(array(false,true));
