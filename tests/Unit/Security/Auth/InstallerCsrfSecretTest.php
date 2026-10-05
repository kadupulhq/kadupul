<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function installer_csrf_probe(string $path): array
{
    $root = dirname(__DIR__, 4);
    $source = file_get_contents($root . '/lib/installer.php');
    $program = '$config = ' . var_export(array('base_path' => $root, 'path_csrf_web_root' => $root, 'path_csrf_secret' => $path), true) . ';';
    $program .= <<<'CODE'
$GLOBALS['writes'] = array();
function log_install_debug(...$args) {}
function log_install_medium(...$args) {}
function log_install_high(...$args) {}
function is_resource_writable($path) { return true; }
function install_create_csrf_secret($path) { $GLOBALS['writes'][] = $path; }
CODE;
    $csrf = file_get_contents($root . '/include/csrf.php');
    foreach (array('cacti_csrf_external_secret_path', 'cacti_csrf_external_path_is_safe') as $function) {
        $program .= test_php_function_source($csrf, $function);
    }
    $program .= 'class Installer { const PROGRESS_CSRF_BEGIN = 1; const PROGRESS_CSRF_END = 2; function setProgress($value) {} ';
    $program .= test_php_function_source($source, 'setCSRFSecret');
    $program .= 'function run() { $this->setCSRFSecret(); } } (new Installer())->run(); print json_encode($GLOBALS["writes"]);';
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    expect($err)->toBe('');
    return json_decode($out, true);
}

test('installer refuses an external secret under the document root', function () {
    expect(installer_csrf_probe(dirname(__DIR__, 4) . '/include/secret-must-not-be-written'))->toBe(array());
});

test('installer creates an external secret and normalizes a directory', function () {
    $dir = sys_get_temp_dir() . '/kadupul-installer-csrf-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    try {
        expect(installer_csrf_probe($dir))->toBe(array(realpath($dir) . '/csrf-secret.php'));
    } finally {
        rmdir($dir);
    }
});

test('database-backed CSRF does not require a writable vendor secret', function () {
    $root = dirname(__DIR__, 4);
    $program = <<<'CODE'
$root = $argv[1];
$config = array('base_path' => $root, 'poller_id' => 2);
function is_resource_writable($path) { return true; }
function log_install_debug(...$args) {}
function log_install_high(...$args) {}
function clean_up_lines($value) { return $value; }
function __($message) { return $message; }
require $root . '/lib/installer.php';
$class = new ReflectionClass('Installer');
$installer = $class->newInstanceWithoutConstructor();
$mode = $class->getProperty('mode');
$mode->setValue($installer, Installer::MODE_POLLER);
$result = $class->getMethod('getPermissions')->invoke($installer);
print json_encode($result);
CODE;
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    expect($err)->toBe('');
    $result = json_decode($out, true);
    expect($result['always'])->not->toHaveKey($root . '/include/vendor/csrf/csrf-secret.php');
});
