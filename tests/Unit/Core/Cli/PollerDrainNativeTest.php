<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// cli/poller_output_empty.php is a shim; the drain loop it used to hold now runs in this worker.
test('production drain worker fails closed on unavailable writers counts and progress', function ($mode) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/drain-native-' . bin2hex(random_bytes(8));
    foreach (array('','/bin','/include','/lib') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    foreach (array('poller','data_query','dsstats','dsdebug','boost','rrd') as $lib) {
        file_put_contents($dir . '/lib/' . $lib . '.php', '<?php');
    }
    copy($root . '/bin/legacy-poller-output-empty.php', $dir . '/bin/legacy-poller-output-empty.php');
    copy($root . '/bin/worker-io.php', $dir . '/bin/worker-io.php');
    $parent = $this->getTestResultObject()->getCodeCoverage();
    $coverageOptions = array();
    $coverageEnvironment = array();
    $bootstrap = '<?php ';
    if ($parent !== null) {
        require_once $root . '/tests/Symfony/WorkerIoCoverage.php';
        list($coverageOptions, $coverageEnvironment) = Kadupul\Tests\WorkerIoCoverage::configuration($root, $dir, 'bin/legacy-poller-output-empty.php', $mode);
    }
    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/drain-cli-bootstrap.php', true) . ';';
    file_put_contents($dir . '/include/cli_check.php', $bootstrap);
    try {
        $process = proc_open(array_merge(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~'), $coverageOptions, array($dir . '/bin/legacy-poller-output-empty.php')), array(0 => array('pipe','r'),1 => array('pipe','w'),2 => array('pipe','w')), $pipes, null, array_merge(getenv(), array('DRAIN_FIXTURE' => $dir,'DRAIN_MODE' => $mode), $coverageEnvironment));
        fwrite($pipes[0], '[]');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(in_array($mode, array('success','empty'), true) ? 0 : 1);
        expect(file_exists($dir . '/closed'))->toBe($mode !== 'init');
        $statuses = array('success' => 'ok','empty' => 'ok','init' => 'rrd_unavailable','count' => 'count_unavailable','recount' => 'no_progress','stalled' => 'no_progress','write' => 'no_progress');
        expect($error)->toBe('');
        expect($output)->toBe('KADUPUL_POLLER_OUTPUT_EMPTY_RESULT=' . json_encode(array('status' => $statuses[$mode], 'updates' => $mode === 'success' ? 1 : 0)) . PHP_EOL);
        if ($parent !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $parent->merge(Kadupul\Tests\WorkerIoCoverage::load($reports[0], $root, 'bin/legacy-poller-output-empty.php', $mode));
        }
    } finally {
        foreach (array('/bin','/include','/lib','') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }rmdir($dir . $suffix);
        }
    }
})->with(array('success','empty','init','count','recount','stalled','write'));
