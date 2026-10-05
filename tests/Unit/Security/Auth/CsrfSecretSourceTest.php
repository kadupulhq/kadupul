<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

/*
 * csrf-magic generates its own secret and writes it beside itself, under the
 * document root, whenever it is not handed one. Kadupul hands it the
 * packager-managed file outside the document root, or a database secret.
 */

function csrf_secret_run(array $scenario): array
{
    $root = dirname(__DIR__, 4);
    $scenario += array('settings' => array(), 'path_csrf_secret' => '', 'base_path' => $root, 'install' => false, 'document_root' => $root);

    $program = <<<'PHP'
$scenario = json_decode($argv[2], true);
$_SERVER['DOCUMENT_ROOT'] = $scenario['document_root'];
if ($scenario['install']) {
    define('IN_CACTI_INSTALL', true);
}
$config = array(
    'include_path' => $argv[1] . '/include',
    'base_path' => $scenario['base_path'],
    'url_path' => '/kadupul/',
    'is_web' => true,
    'path_csrf_secret' => $scenario['path_csrf_secret'],
    'path_csrf_web_root' => $scenario['path_csrf_web_root'] ?? '',
);
$_SESSION = array();
$GLOBALS['writes'] = array();
$GLOBALS['logs'] = array();
function read_config_option($name, $force = false) { return $GLOBALS['scenario']['settings'][$name] ?? ''; }
function set_config_option($name, $value, $remote = false) {
    $GLOBALS['scenario']['settings'][$name] = $value;
    $GLOBALS['writes'][] = $name;
}
function db_execute_prepared($sql,$params) {
    if(str_starts_with($sql,'INSERT IGNORE')) { if(!isset($GLOBALS['scenario']['settings'][$params[0]])) { $GLOBALS['scenario']['settings'][$params[0]]=$params[1];$GLOBALS['writes'][]=$params[0]; } }
    elseif(($GLOBALS['scenario']['settings'][$params[1]]??null)===$params[2]) { $GLOBALS['scenario']['settings'][$params[1]]=$params[0];$GLOBALS['writes'][]=$params[1]; }
    return true;
}
function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') { $GLOBALS['logs'][] = $message; }
$GLOBALS['scenario'] = $scenario;
register_shutdown_function(function () {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    print json_encode(array(
        'secret' => $GLOBALS['csrf']['secret'],
        'allow_ip' => $GLOBALS['csrf']['allow-ip'],
        'settings' => $GLOBALS['scenario']['settings'],
        'writes' => $GLOBALS['writes'],
        'logs' => $GLOBALS['logs'],
        'session' => $_SESSION,
    ));
    $GLOBALS['nativeChildCoverageMarkers'][] = 'secret-state-readback';
});
require $argv[1] . '/include/csrf.php';
PHP;

    $process = proc_open(
        child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, json_encode($scenario)), $coverage_dir, child_coverage_registration(__FILE__, 'csrf-secret-source', array($scenario), array('secret-state-readback'), array('include/csrf.php'), array())),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    child_coverage_collect($coverage_dir);

    expect($stderr)->toBe('');

    return json_decode($stdout, true);
}

function csrf_secret_directory(): string
{
    $dir = sys_get_temp_dir() . '/kadupul-csrf-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);

    return $dir;
}

test('a new install stores a random database secret and hands it to csrf-magic', function () {
    $result = csrf_secret_run(array());

    expect($result['secret'])->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($result['settings']['csrf_secret'])->toBe($result['secret'])
        ->and($result['writes'])->toBe(array('csrf_secret'));
});

test('a stored database secret is used as it is', function () {
    $stored = str_repeat('5a', 20);
    $result = csrf_secret_run(array('settings' => array('csrf_secret' => $stored)));

    expect($result['secret'])->toBe($stored)
        ->and($result['writes'])->toBe(array());
});

test('a secret file outside the document root is used, raw or in the legacy wrapper', function (string $contents, string $expected) {
    $dir = csrf_secret_directory();
    file_put_contents($dir . '/csrf-secret.php', $contents);

    try {
        $result = csrf_secret_run(array('path_csrf_secret' => $dir . '/csrf-secret.php', 'settings' => array('csrf_secret' => str_repeat('0', 40))));
    } finally {
        unlink($dir . '/csrf-secret.php');
        rmdir($dir);
    }

    expect($result['secret'])->toBe($expected)
        ->and($result['writes'])->toBe(array());
})->with(array(
    'raw' => array(str_repeat('ab', 20) . "\n", str_repeat('ab', 20)),
    'wrapper' => array('<?php $secret = "' . str_repeat('cd', 20) . '";' . "\n", str_repeat('cd', 20)),
));

test('a secret file under the document root is refused for the database secret', function () {
    $base = csrf_secret_directory();
    file_put_contents($base . '/csrf-secret.php', str_repeat('ef', 20));

    try {
        $result = csrf_secret_run(array('base_path' => $base, 'path_csrf_secret' => $base . '/csrf-secret.php', 'settings' => array('csrf_secret' => str_repeat('12', 20))));
    } finally {
        unlink($base . '/csrf-secret.php');
        rmdir($base);
    }

    expect($result['secret'])->toBe(str_repeat('12', 20))
        ->and(implode("\n", $result['logs']))->toContain('WARNING: The configured external CSRF secret is unavailable, invalid or under the document root, using the database secret instead');
});

test('an install in progress uses a per-session secret and writes no settings', function () {
    $result = csrf_secret_run(array('install' => true));

    expect($result['secret'])->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($result['session']['cacti_bootstrap_csrf_secret'])->toBe($result['secret'])
        ->and($result['writes'])->toBe(array());
});


test('an aliased install refuses a secret inside the actual document root', function () {
    $base = csrf_secret_directory();
    $served = csrf_secret_directory();
    file_put_contents($served . '/csrf-secret.php', str_repeat('de', 20));
    try {
        $result = csrf_secret_run(array('base_path' => $base, 'document_root' => $served, 'path_csrf_secret' => $served . '/csrf-secret.php', 'settings' => array('csrf_secret' => str_repeat('ab', 20))));
        expect($result['secret'])->toBe(str_repeat('ab', 20));
    } finally {
        unlink($served . '/csrf-secret.php');
        rmdir($served);
        rmdir($base);
    }
});

test('an external symlink into the actual document root is refused', function () {
    $base = csrf_secret_directory();
    $served = csrf_secret_directory();
    $external = csrf_secret_directory();
    file_put_contents($served . '/csrf-secret.php', str_repeat('de', 20));
    symlink($served . '/csrf-secret.php', $external . '/secret');
    try {
        $result = csrf_secret_run(array('base_path' => $base, 'document_root' => $served, 'path_csrf_secret' => $external . '/secret', 'settings' => array('csrf_secret' => str_repeat('ab', 20))));
        expect($result['secret'])->toBe(str_repeat('ab', 20));
    } finally {
        unlink($external . '/secret');
        unlink($served . '/csrf-secret.php');
        rmdir($external);
        rmdir($served);
        rmdir($base);
    }
});


test('a dangling external symlink is rejected before an installer write', function () {
    $dir = csrf_secret_directory();
    symlink($dir . '/missing-target', $dir . '/secret');
    $source = file_get_contents(dirname(__DIR__, 4) . '/include/csrf.php');
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
    $program = '$config = ' . var_export(array('base_path' => dirname(__DIR__, 4)), true) . ';';
    $program .= test_php_function_source($source, 'cacti_csrf_external_secret_path') . test_php_function_source($source, 'cacti_csrf_external_path_is_safe');
    $program .= 'print json_encode(cacti_csrf_external_path_is_safe($argv[1]));';
    try {
        $process = proc_open(array(PHP_BINARY, '-r', $program, $dir . '/secret'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        expect($err)->toBe('')->and(json_decode($out))->toBeFalse();
    } finally {
        unlink($dir . '/secret');
        rmdir($dir);
    }
});

test('web requests without a trusted served root refuse external secrets', function () {
    $dir = csrf_secret_directory();
    file_put_contents($dir . '/csrf-secret.php', str_repeat('ab', 20));
    try {
        $result = csrf_secret_run(array('document_root' => '', 'path_csrf_secret' => $dir . '/csrf-secret.php', 'settings' => array('csrf_secret' => str_repeat('12', 20))));
    } finally {
        unlink($dir . '/csrf-secret.php');
        rmdir($dir);
    }
    expect($result['secret'])->toBe(str_repeat('12', 20));
});
