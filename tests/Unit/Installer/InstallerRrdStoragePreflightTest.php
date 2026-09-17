<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('installer rejects unsafe storage before database upgrades even when forced', function ($mode, $ready) {
    if ($mode === 'windows-readonly' && posix_geteuid() === 0) {
        $this->markTestSkipped('Root bypasses write permissions.');
    }
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/installer-rrd-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/rra', 0700);
    $parentCoverage = $this->getTestResultObject()->getCodeCoverage();
    $coverage = '';
    if ($parentCoverage !== null) {
        $coverage = 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); define("RRD_TEST_INSTALLER_COVERAGE", true); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap = <<<'FIXTURE'
function __($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }
function read_config_option($key, ...$args) { return $key === 'storage_location' && $GLOBALS['mode'] === 'proxy'; }
function is_resource_writable($path) { return true; }
function log_install_debug(...$args) {}
function log_install_medium(...$args) {}
function log_install_always(...$args) { throw new LogicException('upgrade boundary reached'); }
function clean_up_lines($text) { return $text; }
define('CACTI_VERSION', 'test');
$config = array('base_path' => $root, 'rra_path' => __DIR__ . '/rra', 'cacti_server_os' => strpos($mode, 'windows') === 0 ? 'win32' : 'unix');
if ($mode === 'missing' || $mode === 'windows-missing') { $config['rra_path'] .= '/missing'; }
if ($mode === 'windows-file') { $config['rra_path'] = __FILE__; }
if ($mode === 'windows-readonly') { chmod(__DIR__ . '/rra', 0555); }
if ($mode === 'group' || $mode === 'trusted-group') { chmod(__DIR__ . '/rra', 0770); }
if ($mode === 'trusted-group') { $config['rrd_maintenance_trusted_gids'] = array(filegroup(__DIR__ . '/rra')); }
require $root . '/lib/installer.php';
$reflection = new ReflectionClass('Installer');
$installer = $reflection->newInstanceWithoutConstructor();
$property = $reflection->getProperty('mode'); if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); } $property->setValue($installer, Installer::MODE_UPGRADE);
$method = $reflection->getMethod('getPermissions'); if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
$permissions = $method->invoke($installer);
$method = $reflection->getMethod('install'); if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
try { $method->invoke($installer); throw new Exception('install unexpectedly returned'); }
catch (RuntimeException $error) { $outcome = $error->getMessage(); }
catch (LogicException $error) { $outcome = $error->getMessage(); }
echo json_encode(array($permissions['always'][$config['rra_path']], $outcome));
FIXTURE;
    try {
        file_put_contents($dir . '/probe.php', '<?php ' . $coverage . '$root = ' . var_export($root, true) . '; $mode = ' . var_export($mode, true) . ';' . $bootstrap);
        $args = array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~');
        if ($mode === 'no-posix') {
            $args = array_merge($args, array('-d', 'disable_functions=posix_geteuid'));
        }
        $args[] = $dir . '/probe.php';
        $process = proc_open($args, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect($error)->toBe('')->and(proc_close($process))->toBe(0, $output);
        $result = json_decode($output, true);
        expect($result[0])->toBe($ready);
        if ($ready) {
            expect($result[1])->toBe('upgrade boundary reached');
        } else {
            expect($result[1])->toContain('RRD storage is not ready');
            if (strpos($mode, 'windows') !== 0) {
                expect($result[1])->toContain('rrd_maintenance_trusted_uids')->toContain('rrd_maintenance_trusted_gids');
            }
        }
        if ($parentCoverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $parentCoverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        chmod($dir . '/rra', 0700);
        rmdir($dir . '/rra');
        rmdir($dir);
    }
})->with(array(array('missing', false), array('group', false), array('no-posix', false), array('trusted-group', true), array('private', true), array('windows', true), array('windows-missing', false), array('windows-file', false), array('windows-readonly', false), array('proxy', true)));
