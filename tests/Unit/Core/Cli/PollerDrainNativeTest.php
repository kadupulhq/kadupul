<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production drain CLI fails closed on unavailable writers counts and progress', function ($mode) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/drain-native-' . bin2hex(random_bytes(8));
    foreach (array('','/cli','/include','/lib') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    foreach (array('poller','data_query','dsstats','dsdebug','boost','rrd') as $lib) {
        file_put_contents($dir . '/lib/' . $lib . '.php', '<?php');
    }
    copy($root . '/cli/poller_output_empty.php', $dir . '/cli/poller_output_empty.php');
    $parent = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($parent !== null) {
        foreach (array('RRD_TEST_COVERAGE_DIRECTORY' => $dir,'RRD_TEST_CLI_COVERAGE_COPY' => $dir . '/cli/poller_output_empty.php','RRD_TEST_CLI_COVERAGE_SOURCE' => $root . '/cli/poller_output_empty.php') as $name => $value) {
            $bootstrap .= 'define(' . var_export($name, true) . ',' . var_export($value, true) . ');';
        }
        $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/drain-cli-bootstrap.php', true) . ';';
    file_put_contents($dir . '/include/cli_check.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~',$dir . '/cli/poller_output_empty.php'), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes, null, array_merge(getenv(), array('DRAIN_FIXTURE' => $dir,'DRAIN_MODE' => $mode)));
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(in_array($mode, array('success','empty'), true) ? 0 : 1);
        expect(file_exists($dir . '/closed'))->toBe($mode !== 'init');
        if (in_array($mode, array('success','empty'), true)) {
            expect($error)->toBe('')->and($output)->toContain('RRD updates made this pass');
        } else {
            expect($error)->toStartWith('ERROR:')->and($output)->not->toContain('RRD updates made');
        }
        if ($parent !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $parent->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (array('/cli','/include','/lib','') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }rmdir($dir . $suffix);
        }
    }
})->with(array('success','empty','init','count','recount','stalled','write'));
