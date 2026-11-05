<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// #777: RRDtool applies tune options one by one, so a rejected later option
// left the earlier ones stored while rrdtool_function_tune() reported nothing.

/** Run the production rrdtool_function_tune() in its own process. */
function rrd_tune_all_or_nothing(object $test, string $dir, string $rrdtool, array $tune): array
{
    $root = dirname(__DIR__, 4);
    $config = array('cacti_server_os' => 'unix', 'rra_path' => $dir, 'include_path' => $dir, 'is_web' => true);
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    $prelude = $coverage === null ? '' : 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    file_put_contents($dir . '/global_arrays.php', '<?php $data_source_types = array(1 => "GAUGE");');
    file_put_contents($dir . '/tune.php', '<?php ' . $prelude . '$config = ' . var_export($config, true) . ';' .
        'define("CACTI_LOCALE", "en-US"); define("POLLER_VERBOSITY_DEBUG", 5);' .
        'function read_config_option($name) { return $name === "path_rrdtool" ? ' . var_export($rrdtool, true) . ' : ""; }' .
        'function debounce_run_notification(...$args) { return true; }' .
        'function cacti_log($message, ...$args) { file_put_contents(__DIR__ . "/log", $message . PHP_EOL, FILE_APPEND); }' .
        'function cacti_escapeshellcmd($value) { return escapeshellcmd($value); } function cacti_escapeshellarg($value) { return escapeshellarg($value); }' .
        'function get_data_source_item_name($id) { return "value"; } function get_data_source_path($id, $expand) { return __DIR__ . "/source.rrd"; }' .
        'require ' . var_export($root . '/lib/rrd.php', true) . ';' .
        'echo json_encode(rrdtool_function_tune(' . var_export($tune, true) . '));');
    // Backups go to the temporary directory; point it at the fixture to see leftovers.
    $environment = array_merge(getenv(), array('TMPDIR' => $dir));
    unset($environment['RRDCACHED_ADDRESS']);
    $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/tune.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $environment);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error);
    if ($coverage !== null) {
        $reports = glob($dir . '/*.coverage');
        expect($reports)->toHaveCount(1);
        $coverage->merge(unserialize(file_get_contents($reports[0])));
        unlink($reports[0]);
    }

    return array('result' => json_decode($output, true, 512, JSON_THROW_ON_ERROR), 'log' => is_file($dir . '/log') ? file_get_contents($dir . '/log') : '', 'backups' => glob($dir . '/kadupul-tune-*'));
}

function rrd_tune_fixture(): string
{
    $dir = sys_get_temp_dir() . '/rrd-tune-all-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);

    return realpath($dir);
}

function rrd_tune_cleanup(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}

test('a tune command that fails part-way leaves the RRDfile unchanged and reports the failure', function (int $exit) {
    $dir = rrd_tune_fixture();
    try {
        file_put_contents($dir . '/source.rrd', 'original');
        // The boundary changes the file, as RRDtool does before a later option fails.
        file_put_contents($dir . '/rrdtool', "#!/bin/sh\nprintf ' partly tuned' >> \"\$2\"\nexit $exit\n");
        chmod($dir . '/rrdtool', 0700);
        chmod($dir . '/source.rrd', 0640);

        $run = rrd_tune_all_or_nothing($this, $dir, $dir . '/rrdtool', array('data_source_id' => 1, 'data-source-type' => '', 'heartbeat' => 900, 'minimum' => 'abc', 'maximum' => '', 'data-source-rename' => ''));

        clearstatcache();
        if ($exit === 0) {
            expect($run['result'])->toBeTrue()
                ->and(file_get_contents($dir . '/source.rrd'))->toBe('original partly tuned')
                ->and($run['log'])->not->toContain('tune failed');
        } else {
            expect($run['result'])->toBeFalse()
                ->and(file_get_contents($dir . '/source.rrd'))->toBe('original')
                ->and(fileperms($dir . '/source.rrd') & 0777)->toBe(0640)
                ->and($run['log'])->toContain("RRDtool tune failed for '$dir/source.rrd'; the RRDfile was restored unchanged.");
        }
        expect($run['backups'])->toBe([]);
    } finally {
        rrd_tune_cleanup($dir);
    }
})->with(array('rejected later option' => 1, 'every option applied' => 0));

test('a real RRDtool keeps the earlier heartbeat when a later minimum is rejected', function () {
    $rrdtool = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : (is_executable('/opt/homebrew/bin/rrdtool') ? '/opt/homebrew/bin/rrdtool' : ''));
    if ($rrdtool === '') {
        $this->markTestSkipped('RRDtool is not installed.');
    }
    $dir = rrd_tune_fixture();
    try {
        $create = proc_open(array($rrdtool, 'create', $dir . '/source.rrd', '--step', '300', 'DS:value:GAUGE:600:U:U', 'RRA:AVERAGE:0.5:1:10'), array(), $pipes);
        expect(proc_close($create))->toBe(0);
        $before = hash_file('sha256', $dir . '/source.rrd');

        $run = rrd_tune_all_or_nothing($this, $dir, $rrdtool, array('data_source_id' => 1, 'data-source-type' => '', 'heartbeat' => 900, 'minimum' => 'abc', 'maximum' => '', 'data-source-rename' => ''));

        expect($run['result'])->toBeFalse()
            ->and(hash_file('sha256', $dir . '/source.rrd'))->toBe($before)
            ->and($run['backups'])->toBe([]);

        $good = rrd_tune_all_or_nothing($this, $dir, $rrdtool, array('data_source_id' => 1, 'data-source-type' => '', 'heartbeat' => 900, 'minimum' => '0', 'maximum' => '', 'data-source-rename' => ''));
        $info = shell_exec(escapeshellarg($rrdtool) . ' info ' . escapeshellarg($dir . '/source.rrd'));

        expect($good['result'])->toBeTrue()
            ->and($info)->toContain('ds[value].minimal_heartbeat = 900')
            ->and($info)->toContain('ds[value].min = 0.0000000000e+00');
    } finally {
        rrd_tune_cleanup($dir);
    }
});
