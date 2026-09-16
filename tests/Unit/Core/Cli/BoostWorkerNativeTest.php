<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production Boost owns, supervises and reaps actual worker processes', function ($mode) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/boost-worker-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/include', 0700);
    mkdir($dir . '/lib', 0700);
    foreach (array('poller','boost','dsstats','rrdcheck','rrd') as $lib) {
        file_put_contents($dir . '/lib/' . $lib . '.php', '<?php');
    }
    touch($dir . '/workers.log');
    copy($root . '/poller_boost.php', $dir . '/poller_boost.php');
    $parent = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($parent !== null) {
        $bootstrap .= 'if (in_array("--help",$_SERVER["argv"],true)) {' .
            'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');' .
            'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($dir . '/poller_boost.php', true) . ');' .
            'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/poller_boost.php', true) . ');' .
            'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';}';
    }
    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/boost-worker-bootstrap.php', true) . ';';
    file_put_contents($dir . '/include/cli_check.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY,'-d','pcov.directory=/','-d','pcov.exclude=~/(include/vendor|tests)/~',$dir . '/poller_boost.php','--help'), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes, null, array_merge(getenv(), array('BOOST_FIXTURE' => $dir,'BOOST_MODE' => $mode)));
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($error !== '') {
            throw new RuntimeException($error . $output);
        }
        expect($status)->toBe(0);
        $result = json_decode(file_get_contents($dir . '/result.json'), true);
        expect($result[0])->toBe($mode === 'shutdown' ? null : $mode === 'success');
        expect(file($dir . '/reaped'))->toHaveCount(2);
        foreach ($result[1] as $pid) {
            expect(posix_kill($pid, 0))->toBeFalse();
        }
        if ($mode !== 'shutdown') {
            expect($result[2])->toBeLessThan(5.0);
        }
        if ($parent !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $parent->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (array('/include','/lib','') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with(array('success','early-crash','timeout','launch-failure','shutdown'));
