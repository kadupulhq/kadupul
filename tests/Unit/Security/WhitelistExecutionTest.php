<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function runWhitelistProbe($test, $program, array $arguments = array())
{
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/whitelist-test-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    mkdir($dir . '/lib', 0700);
    $files = array('include/auth.php', 'lib/api_data_source.php', 'lib/poller.php', 'lib/template.php', 'lib/utility.php');
    foreach ($files as $file) {
        file_put_contents($dir . '/' . $file, '<?php');
    }
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("WHITELIST_EXEC_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(
            array_merge(array(PHP_BINARY, '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root), $arguments),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start whitelist probe');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
        return $stdout;
    } finally {
        foreach ($files as $file) {
            unlink($dir . '/' . $file);
        }
        foreach (glob($dir . '/*.coverage') as $report) {
            unlink($report);
        }
        rmdir($dir . '/include');
        rmdir($dir . '/lib');
        rmdir($dir);
    }
}

test('native argv execution preserves metacharacters and reports exit status', function ($exit, $timeout) {
    $program = <<<'PHP'
require $argv[1] . '/lib/functions.php';
$payload = 'spaces ; & | $(echo injected) `echo injected` "quotes"';
$output = array();
$timeout = json_decode($argv[3], true);
$result = cacti_exec(PHP_BINARY, array('-r', 'echo $argv[1]; exit((int) $argv[2]);', $payload, $argv[2]), $output, $timeout);
$string = cacti_exec_string(PHP_BINARY, array('-r', 'echo $argv[1]; exit((int) $argv[2]);', $payload, $argv[2]), $timeout);
echo json_encode(array($result, $output, $string));
PHP;
    $result = json_decode(runWhitelistProbe($this, $program, array((string) $exit, json_encode($timeout))), true, 512, JSON_THROW_ON_ERROR);
    $payload = 'spaces ; & | $(echo injected) `echo injected` "quotes"';
    expect($result)->toBe(array($exit, array($payload), $exit === 0 ? $payload : false));
})->with(array(0, 7))->with(array(5, false, null));

test('native argv execution terminates a child at its deadline', function () {
    $program = <<<'PHP'
require $argv[1] . '/lib/functions.php';
$config = array('is_web' => false, 'base_path' => getcwd(), 'config_options_array' => array(
    'selective_debug' => '', 'client_timezone_support' => '', 'log_destination' => '0', 'path_cactilog' => ''));
$output = array();
$start = microtime(true);
$status = cacti_exec(PHP_BINARY, array('-r', 'sleep(5); echo "not terminated";'), $output, 1);
echo json_encode(array($status, $output, microtime(true) - $start));
PHP;
    $result = json_decode(runWhitelistProbe($this, $program), true, 512, JSON_THROW_ON_ERROR);
    expect($result[0])->toBe(1);
    expect($result[1])->toBe(array());
    expect($result[2])->toBeGreaterThanOrEqual(1);
    expect($result[2])->toBeLessThan(4);
});
