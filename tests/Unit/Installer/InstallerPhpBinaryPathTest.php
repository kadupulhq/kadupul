<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('installer probes a PHP executable whose filename contains shell syntax', function () {
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
$paths->setValue($installer, array('path_php_binary' => array('install_check' => 'file_exists')));
$method = $class->getMethod('setPaths');
$method->setAccessible(true);
$method->invoke($installer, array('path_php_binary' => $argv[2]));
echo json_encode($saved);
PHP;

    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . $program, $root, $binary),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)
            ->and($error)->toBe('')
            ->and(json_decode($output, true))->toBe(array('path_php_binary' => $binary))
            ->and(file_exists($dir . '/INJECTED'))->toBeFalse();
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
