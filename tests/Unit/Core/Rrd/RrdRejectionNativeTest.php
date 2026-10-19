<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('a stale timestamp is consumed while a schema refusal is reported for dead-lettering', function () {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-rejection-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    touch($dir . '/stale.rrd');
    touch($dir . '/schema.rrd');
    // The writer answers each update as RRDtool would for that file.
    $server = <<<'SERVER'
while (($command = fgets(STDIN)) !== false) {
    if (strpos($command, 'stale.rrd') !== false) {
        echo "ERROR: stale.rrd: illegal attempt to update using time 1699999800 when last update time is 1700000000 (minimum one second step)\n";
    } else {
        echo "ERROR: unknown DS name 'value'\n";
    }
    fflush(STDOUT);
}
SERVER;
    file_put_contents($dir . '/rrd-server', '#!' . PHP_BINARY . "\n<?php " . $server);
    chmod($dir . '/rrd-server', 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('cacti_server_os'=>'unix', 'rra_path'=>__DIR__, 'is_web'=>false, 'rrd_command_timeout'=>10);
require $root.'/include/global_constants.php';
define('CACTI_LOCALE', 'en-US');
$logs = array();
function read_config_option($key) { return $key === 'path_rrdtool' ? __DIR__.'/rrd-server' : ''; }
function cacti_log($message, ...$args) { $GLOBALS['logs'][] = $message; }
function cacti_session_close() {}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function get_rrdtool_version() { return '1.7'; }
function cacti_version_compare($a, $b, $operator) { return version_compare($a, $b, $operator); }
require $root.'/lib/rrd.php';
$pipe = rrd_init(false, false, true);
$updates = array();
foreach (array('stale', 'schema') as $name) {
    $updates[__DIR__ . "/$name.rrd"] = array('local_data_id' => 1, 'data_template_id' => 0, 'times' => array(1699999800 => array('value' => '1')));
}
$result = rrdtool_function_update($updates, $pipe, $completed, $rejected);
rrd_close($pipe);
$base = fn($paths) => array_combine(array_map('basename', array_keys($paths)), array_values($paths));
echo json_encode(array($result, $base($completed), $base($rejected), $logs));
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        list($result, $completed, $rejected, $logs) = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        // The stale sample is acknowledged as consumed; the refused one is retained with its reason.
        expect($result)->toBeFalse()
            ->and($completed)->toBe(array('stale.rrd' => array(1699999800 => false)))
            ->and($rejected)->toBe(array('schema.rrd' => "unknown DS name 'value'"))
            ->and($logs[0])->toStartWith('ERROR: RRDtool rejected sample (not written): ')
            ->and($logs[1])->toStartWith('ERROR: RRD pending sample retained for retry: ');
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
});

test('real RRDtool rejects old and duplicate samples without claiming they were stored', function (bool $persistent) {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required.');
    }
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-past-update-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $program = '<?php ';
    if ($coverage !== null) {
        $program .= 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program .= '$root=' . var_export($root, true) . ';$binary=' . var_export($binary, true) . ';$persistent=' . var_export($persistent, true) . ';';
    $program .= <<<'PHP'
$config = ['cacti_server_os' => 'unix', 'rra_path' => __DIR__, 'is_web' => false, 'rrd_command_timeout' => 10];
require $root . '/include/global_constants.php';
define('CACTI_LOCALE', 'en-US');
function read_config_option($key) { return $key === 'path_rrdtool' ? $GLOBALS['binary'] : ''; }
function cacti_log(...$args) {}
function cacti_session_close() {}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function get_rrdtool_version() { return '1.7'; }
function cacti_version_compare(...$args) { return version_compare(...$args); }
require $root . '/lib/rrd.php';
$path = __DIR__ . '/sample.rrd';
$pipe = $persistent ? rrd_init(false, false, true) : false;
if ($persistent && !is_resource($pipe)) { throw new RuntimeException('Writer unavailable'); }
if (rrdtool_execute(['create', $path, '--start', '1699999900', '--step', '60', 'DS:value:GAUGE:600:U:U', 'RRA:AVERAGE:0.5:1:20'], false, RRDTOOL_OUTPUT_BOOLEAN, $pipe) !== true
    || rrdtool_execute(['update', $path, '1700000300:5'], false, RRDTOOL_OUTPUT_BOOLEAN, $pipe) !== true) {
    throw new RuntimeException('Unable to seed real RRD');
}
$before = hash_file('sha256', $path);
$updates = [$path => ['local_data_id' => 1, 'data_template_id' => 0, 'times' => [1700000000 => ['value' => '99'], 1700000300 => ['value' => '88']]]];
$stale = rrdtool_function_update($updates, $pipe, $completed, $rejected);
$oldCompleted = $completed[$path] ?? [];
$unchanged = hash_file('sha256', $path) === $before;
$updates[$path]['times'] = [1700000360 => ['value' => '7']];
$fresh = rrdtool_function_update($updates, $pipe, $completed);
$freshCompleted = $completed[$path] ?? [];
$stored = rrdtool_execute(['lastupdate', $path], false, RRDTOOL_OUTPUT_STDOUT, $pipe);
$beforeReplay = hash_file('sha256', $path);
$replay = rrdtool_function_update($updates, $pipe, $completed);
$replayCompleted = $completed[$path] ?? [];
$replayUnchanged = hash_file('sha256', $path) === $beforeReplay;
$updates[$path]['times'] = [1700000420 => ['value' => '0']];
$zero = rrdtool_function_update($updates, $pipe, $completed);
$zeroCompleted = $completed[$path] ?? [];
$final = rrdtool_execute(['lastupdate', $path], false, RRDTOOL_OUTPUT_STDOUT, $pipe);
rrd_close($pipe);
echo json_encode([$stale, $oldCompleted, $rejected, $unchanged, $fresh, $freshCompleted, $stored, $replay, $replayCompleted, $replayUnchanged, $zero, $zeroCompleted, $final], JSON_THROW_ON_ERROR);
PHP;
    try {
        file_put_contents($dir . '/probe.php', $program);
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect($result[0])->toBeFalse()->and($result[1])->toBe([1700000000 => false, 1700000300 => false])
            ->and($result[2])->toBe([])->and($result[3])->toBeTrue()
            ->and($result[4])->toBe(1)->and($result[5])->toBe([1700000360 => true])
            ->and($result[6])->toContain('1700000360: 7')
            ->and($result[7])->toBeFalse()->and($result[8])->toBe([1700000360 => false])->and($result[9])->toBeTrue()
            ->and($result[10])->toBe(1)->and($result[11])->toBe([1700000420 => true])
            ->and($result[12])->toContain('1700000420: 0');
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
})->with(['one-shot' => false, 'persistent' => true]);
