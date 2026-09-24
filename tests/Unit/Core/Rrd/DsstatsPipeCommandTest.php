<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('Data Source statistics quote RRD paths and skip a path with a NUL', function () {
    $root = dirname(__DIR__, 4);
    $binary = getenv('RRDTOOL_TEST_BINARY');
    $dir = sys_get_temp_dir() . '/dsstats-pipe-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $rrd = $dir . "/it's a.rrd";
    $real = $binary && is_executable($binary);
    if ($real) {
        $now = intdiv(time(), 300) * 300;
        exec(escapeshellarg($binary) . ' create ' . escapeshellarg($rrd) . ' --start ' . ($now - 3000) . ' --step 300 DS:value:GAUGE:600:U:U RRA:AVERAGE:0.5:1:100 RRA:MAX:0.5:1:100', $out, $status);
        expect($status)->toBe(0);
        for ($i = 9; $i >= 1; $i--) {
            exec(escapeshellarg($binary) . ' update ' . escapeshellarg($rrd) . ' ' . ($now - $i * 300) . ':' . $i, $out, $status);
            expect($status)->toBe(0);
        }
    }
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= '$root=' . var_export($root, true) . ';$rrd=' . var_export($rrd, true) . ';$binary=' . var_export($real ? $binary : '', true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('base_path' => $root, 'cacti_server_os' => 'unix');
$logged = $written = array();
require $root . '/include/global_constants.php';
function cacti_log($message, ...$args) { $GLOBALS['logged'][] = $message; }
function read_config_option($key) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_execute($sql, ...$args) { $GLOBALS['written'][] = $sql; return true; }
require $root . '/src/Graphing/Infrastructure/Rrd/UnrepresentableArgument.php';
require $root . '/src/Graphing/Infrastructure/Rrd/PipeEncoder.php';
require $root . '/lib/rrd.php';
require $root . '/lib/dsstats.php';
set_error_handler(function ($level, $message) { throw new ErrorException($message, 0, $level); });
$pipes = array(fopen('php://temp', 'r+'), fopen('php://temp', 'r+'));
$refused = dsstats_obtain_data_source_avgpeak_values(2, "rra/it\0s.rrd", 'daily', $pipes);
rewind($pipes[0]);
$sent = stream_get_contents($pipes[0]);
$stats = array(2 => $refused);
$values = null;
if ($binary !== '') {
    $process = proc_open(array($binary, '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $real_pipes, dirname($rrd));
    $values = dsstats_obtain_data_source_avgpeak_values(1, $rrd, 'daily', $real_pipes);
    fclose($real_pipes[0]);
    proc_close($process);
    $stats[1] = $values;
}
dsstats_write_buffer($stats, 'daily');
echo json_encode(array('refused' => $refused, 'sent' => $sent, 'logged' => $logged, 'values' => $values, 'written' => $written));
SOURCE;
    file_put_contents($dir . '/probe.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error . $output);
        expect($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        // A NUL is refused before anything reaches RRDtool, and the buffer skips the empty entry.
        expect($result['refused'])->toBeNull()
            ->and($result['sent'])->toBe('')
            ->and($result['logged'])->toBe(array('ERROR: Data Source statistics skipped for Local Data ID 2. The RRD path contains a line break or NUL.'));
        if ($real) {
            expect($result['values'])->toBe(array('value' => array('AVG' => '5.000000', 'MAX' => '9.000000')))
                ->and($result['written'])->toHaveCount(1)
                ->and($result['written'][0])->toContain("('1','value','5.000000','9.000000')")->not->toContain("'2'");
        } else {
            expect($result['written'])->toBe(array());
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
});
