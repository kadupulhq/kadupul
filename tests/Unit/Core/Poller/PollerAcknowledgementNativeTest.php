<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production poller files retain failed writes and preserve concurrent arrivals', function ($realtime, $failed) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/poller-native-' . bin2hex(random_bytes(8));
    foreach (array('', '/lib', '/include') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    foreach (array('rrd', 'poller', 'data_query') as $lib) {
        file_put_contents($dir . '/lib/' . $lib . '.php', '<?php');
    }
    file_put_contents($dir . '/cmd_realtime.php', '<?php');
    file_put_contents($dir . '/user_1_1.rrd', 'fixture');
    $parent = $this->getTestResultObject()->getCodeCoverage();
    $coverage = '';
    if ($parent !== null) {
        $coverage = 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');';
        if ($realtime) {
            $coverage .= 'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($dir . '/poller_realtime.php', true) . ');define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/poller_realtime.php', true) . ');';
        }
        $coverage .= 'require ' . var_export($root . '/tests/fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap = '<?php ' . $coverage . 'require ' . var_export($root . '/tests/fixtures/poller-ack-bootstrap.php', true) . ';';
    try {
        if ($realtime) {
            copy($root . '/poller_realtime.php', $dir . '/poller_realtime.php');
            file_put_contents($dir . '/include/cli_check.php', $bootstrap);
            $arguments = array($dir . '/poller_realtime.php', '--graph=1', '--interval=5', '--poller_id=1');
        } else {
            file_put_contents($dir . '/run.php', $bootstrap . 'require ' . var_export($root . '/lib/poller.php', true) . ';$pipe=true;$result=process_poller_output($pipe,1,$deferred);if(getenv("ACK_FAIL")==="rejected"){if($result!==0||$deferred){exit(4);}$GLOBALS["ack_db"]->exec("INSERT INTO poller_output VALUES(1, \'value\', \'2020-01-03\', \'45\')");putenv("ACK_FAIL=after-rejection");$next=process_poller_output($pipe,1,$secondDeferred);if($next!==1||$secondDeferred){exit(5);}putenv("ACK_FAIL=rejected");}db_close();exit($deferred?1:0);');
            $arguments = array($dir . '/run.php');
        }
        $process = proc_open(array_merge(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~'), $arguments), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes, null, array_merge(getenv(), array('ACK_FIXTURE' => $dir,'ACK_REALTIME' => $realtime ? '1' : '0','ACK_FAIL' => is_string($failed) ? $failed : ($failed ? '1' : '0'))));
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if ($error !== '') { throw new RuntimeException($error . $output); }
        expect(proc_close($process))->toBe(($failed && !(!$realtime && $failed === 'rejected')) && !in_array($failed, array('replace', 'replace-space'), true) ? 1 : 0, $error . $output)->and($error)->toBe('');
        $expected = is_string($failed) ? array(array('output' => '42', 'remaining' => $failed === 'page' ? 40001 : 1)) : ($failed ? array('42','43') : array('43'));
        if ($failed === 'rejected') { $expected = array(); }
        if ($failed === 'replace-space') { $expected = array('42 ', '43'); }
        if ($failed === 'replace') { $expected = array('99','43'); }
        if ($failed === 'delete') { $expected = array('42','43'); }
        expect(json_decode(file_get_contents($dir . '/outcome.json'), true))->toBe($expected);
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
            } rmdir($dir . $suffix);
        }
    }
})->with(array(array(false,false),array(false,true),array(true,false),array(true,true),array(false,'replace-space'),array(true,'replace-space'),array(false,'replace'),array(true,'replace'),array(true,'delete'),array(false,'rejected'),array(true,'rejected'),array(false,'mixed'),array(false,'page')));
