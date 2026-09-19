<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production command runner preserves nonzero exit status when the first status observation is unavailable', function ($mode) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/command-exit-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $script = '<?php $mode=' . var_export($mode, true) . ';';
    if ($coverage !== null) {
        $script .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $script .= 'require ' . var_export($root . '/lib/rrd_maintenance.php', true) . ';';
    $script .= <<<'PROBE'
// Inject status-observation failure only; process creation and close are real.
function proc_get_status($process) {
    static $calls = 0;
    $calls++;
    if ($calls === 1 || $GLOBALS['mode'] === 'close') { return false; }
    return array('running' => false, 'exitcode' => 7);
}
$result = rrd_maintenance_run_command(array(PHP_BINARY, '-r', 'echo "retained output"; exit(7);'), null);
echo json_encode($result, JSON_THROW_ON_ERROR);
PROBE;
    try {
        file_put_contents($directory . '/probe.php', $script);
        $process = proc_open(
            array(PHP_BINARY, '-d', 'disable_functions=proc_get_status', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/probe.php'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array('exit' => 7, 'stdout' => 'retained output', 'stderr' => ''));
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
})->with(array('status', 'close'));
