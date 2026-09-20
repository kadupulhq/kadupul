<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('installer validates and canonicalizes submitted PHP paths', function ($shape) {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('The fixture uses Unix symlinks and filename characters.');
    }

    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/installer-php-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $binary = $dir . '/php binary; literal $(touch INJECTED)';
    symlink(PHP_BINARY, $binary);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("RRD_TEST_INSTALLER_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program = <<<'PHP'
$config = array('base_path' => $argv[1], 'cacti_server_os' => 'unix');
$saved = array();
function log_install_debug(...$args) {}
function log_install_high(...$args) {}
function log_install_medium(...$args) {}
function __($value) { return $value; }
function set_install_config_option($name, $value) { $GLOBALS['saved'][$name] = $value; }
require $argv[1] . '/lib/functions.php';
require $argv[1] . '/lib/installer.php';
$class = new ReflectionClass('Installer');
$installer = $class->newInstanceWithoutConstructor();
$paths = $class->getProperty('paths');
$paths->setAccessible(true);
$optional = str_starts_with($argv[3], 'optional_');
$name = $optional ? 'path_spine_config' : 'path_php_binary';
$paths->setValue($installer, array($name => array('install_check' => 'file_exists', 'install_optional' => $optional)));
$method = $class->getMethod('setPaths');
$method->setAccessible(true);
$submitted = $argv[3] === 'array' ? array($argv[2]) : ($argv[3] === 'nul' ? $argv[2] . "\0" : $argv[2]);
if ($argv[3] === 'optional_false') $submitted = false;
if ($argv[3] === 'optional_null') $submitted = null;
$method->invoke($installer, array($name => $submitted));
echo json_encode($saved);
PHP;

    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'disable_functions=shell_exec,exec,popen', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . $program, $root, $binary, $shape),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $expected = str_starts_with($shape, 'optional_') ? array('path_spine_config' => '') : ($shape === 'scalar' ? array('path_php_binary' => realpath(PHP_BINARY)) : array());
        expect(proc_close($process))->toBe(0)
            ->and($error)->toBe('')
            ->and(json_decode($output, true))->toBe($expected)
            ->and(file_exists($dir . '/INJECTED'))->toBeFalse();
        unlink($binary);
        symlink($dir . '/untrusted-replacement', $binary);
        if ($shape === 'scalar') {
            expect(is_file(json_decode($output, true)['path_php_binary']))->toBeTrue();
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
})->with(array('scalar', 'array', 'nul', 'optional_false', 'optional_null'));

test('installer PHP probes fail closed without a shell', function ($scenario) {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/installer-probe-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("RRD_TEST_INSTALLER_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $binary = PHP_BINARY;
    if (in_array($scenario, array('nonzero', 'timeout', 'excess_output', 'flood', 'untrusted'), true)) {
        if (PHP_OS_FAMILY === 'Windows') {
            rmdir($dir);
            $this->markTestSkipped('Executable shebang fixtures require Unix.');
        }
        $binary = $dir . '/probe';
        $code = array(
            'nonzero' => 'echo 49; exit(7);',
            'timeout' => 'usleep(1000000); echo 49;',
            'excess_output' => 'echo str_repeat("x", 1024);',
            'flood' => 'while (true) { echo str_repeat("x", 8192); }',
            'untrusted' => 'file_put_contents(__DIR__ . "/EXECUTED", "bad"); echo 49;',
        )[$scenario];
        file_put_contents($binary, '#!' . PHP_BINARY . "\n<?php " . $code);
        chmod($binary, 0700);
    } elseif ($scenario === 'missing') {
        $binary = $dir . '/not-present';
    }
    $program = <<<'PHP'
require $argv[1] . '/lib/installer.php';
$installer_allowed_php_binaries = $argv[3] === 'untrusted' ? array(PHP_BINARY) : array($argv[2]);
if ($argv[3] === 'empty_allowlist') $installer_allowed_php_binaries = array();
if ($argv[3] === 'invalid_allowlist') $installer_allowed_php_binaries = 'not-an-array';
$class = new ReflectionClass('Installer');
$installer = $class->newInstanceWithoutConstructor();
$probe = $class->getMethod('probePhpBinary');
$probe->setAccessible(true);
echo json_encode($probe->invoke($installer, $argv[2], 7, in_array($argv[3], array('timeout', 'flood'), true) ? 0.1 : 5));
PHP;
    try {
        $disabled = 'shell_exec,exec,popen';
        $missingFunctions = array('no_proc' => 'proc_open', 'no_status' => 'proc_get_status', 'no_terminate' => 'proc_terminate', 'no_close' => 'proc_close');
        if (isset($missingFunctions[$scenario])) {
            $disabled .= ',' . $missingFunctions[$scenario];
        }
        $start = microtime(true);
        $process = proc_open(array(PHP_BINARY, '-d', 'disable_functions=' . $disabled, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . $program, $root, $binary, $scenario), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        expect(file_exists($dir . '/EXECUTED'))->toBeFalse();
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if ($scenario === 'success') {
            expect(trim($result))->toBe('49');
        } else {
            expect($result)->toBeFalse();
        }
        if (in_array($scenario, array('timeout', 'flood'), true) && $coverage === null) {
            expect(microtime(true) - $start)->toBeLessThan(0.9);
        }
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }
    } finally {
        if (is_file($dir . '/probe')) {
            unlink($dir . '/probe');
        }
        foreach (glob($dir . '/*.coverage') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array('success', 'missing', 'nonzero', 'timeout', 'excess_output', 'flood', 'no_proc', 'no_status', 'no_terminate', 'no_close', 'untrusted', 'empty_allowlist', 'invalid_allowlist'));

test('legacy CLI probe callers retain stdout behavior', function () {
    $process = proc_open(array(PHP_BINARY, dirname(__DIR__, 3) . '/install/cli_test.php', '7'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stdout)->toBe('49')->and($stderr)->toBe('');
});
