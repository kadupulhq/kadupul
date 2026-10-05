<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * cli/refresh_csrf.php must rotate the secret web requests read: the stored
 * one when no $path_csrf_secret is set, otherwise the external file, which it
 * must refuse to write under the document root. An exact copy of the script
 * runs against an isolated bootstrap and the shipped include/csrf.php.
 */

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

function refresh_csrf_run($test, array $scenario): array
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/refresh-csrf-' . bin2hex(random_bytes(8));
    $outside = $dir . '-secret';
    foreach (array('cli', 'lib', 'include/vendor/csrf') as $part) {
        mkdir($dir . '/' . $part, 0700, true);
    }
    mkdir($outside, 0700);
    copy($root . '/cli/refresh_csrf.php', $dir . '/cli/refresh_csrf.php');
    if (!empty($scenario['ownership_refused'])) {
        $script = file_get_contents($dir . '/cli/refresh_csrf.php');
        $stub = 'function chown($path,$owner) { throw new \\RuntimeException("Unexpected ownership change"); } function chgrp($path,$group) { throw new \\RuntimeException("Unexpected group change"); }';
        file_put_contents($dir . '/cli/refresh_csrf.php', preg_replace('/<\?php/', '<?php namespace RefreshCsrfOwnershipProbe; ' . $stub, $script, 1));
    }
    if (!empty($scenario['filesystem_failure'])) {
        $failure = $scenario['filesystem_failure'];
        $stub = $failure === 'fallback' ? 'function tempnam($dir,$prefix) { $path = \tempnam(dirname($dir),$prefix); file_put_contents(getenv("REFRESH_CSRF_DIR") . "/fallback-path",$path); return $path; }' : ($failure === 'short_write' ? 'function file_put_contents($path,$contents,$flags=0) { return \file_put_contents($path,substr($contents,0,5),$flags); }' : ($failure === 'readback' ? 'function file_get_contents($path) { return false; }' : ($failure === 'rename' ? 'function rename($from,$to) { return false; }' : 'function tempnam($dir,$prefix) { return false; }')));
        $script = file_get_contents($dir . '/cli/refresh_csrf.php');
        file_put_contents($dir . '/cli/refresh_csrf.php', preg_replace('/<\?php/', '<?php namespace RefreshCsrfFilesystemProbe; ' . $stub, $script, 1));
    }
    if (!empty($scenario['unlink_failure'])) {
        // Namespace only the isolated script to model a failed filesystem call.
        $script = file_get_contents($dir . '/cli/refresh_csrf.php');
        file_put_contents($dir . '/cli/refresh_csrf.php', preg_replace('/<\?php/', '<?php namespace RefreshCsrfUnlinkProbe; function unlink($path) { return false; }', $script, 1));
    }
    file_put_contents($dir . '/lib/poller.php', '<?php');
    file_put_contents($dir . '/lib/utility.php', '<?php');
    file_put_contents($dir . '/lib/csrf_rotation.php', '<?php require ' . var_export($root . '/lib/csrf_rotation.php', true) . ';');
    file_put_contents($dir . '/include/vendor/csrf/csrf-conf.php', '<?php');
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
    file_put_contents($dir . '/include/vendor/csrf/csrf-magic.php', '<?php ' . test_php_function_source(file_get_contents($root . '/include/vendor/csrf/csrf-magic.php'), 'csrf_generate_secret') . ' function csrf_writable($file) { return is_writable(file_exists($file) ? $file : dirname($file)); }');
    if (!empty($scenario['legacy'])) {
        file_put_contents($dir . '/include/vendor/csrf/csrf-secret.php', '<?php $secret = "legacy";');
    }
    $secret = str_replace(array('{outside}', '{root}'), array($outside, $dir), $scenario['secret'] ?? '');
    if (!empty($scenario['existing'])) {
        file_put_contents($secret, '<?php $secret = "old";');
        chmod($secret, $scenario['mode'] ?? 0640);
    }

    $bootstrap = <<<'PHP'
<?php
$config = array('base_path' => getenv('REFRESH_CSRF_DIR'), 'include_path' => getenv('REFRESH_CSRF_DIR') . '/include', 'is_web' => false, 'poller_id' => (int) (getenv('REFRESH_CSRF_ROLE') ?: 1));
if (getenv('REFRESH_CSRF_SECRET') !== '') {
    $config['path_csrf_secret'] = getenv('REFRESH_CSRF_SECRET');
}
$config['path_csrf_web_root'] = getenv('REFRESH_CSRF_WEB_ROOT');
$config['cacti_server_os'] = getenv('REFRESH_CSRF_OS');
$GLOBALS['stored'] = array();
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_execute_prepared($sql,$params,$log=true,$connection=false) { if($connection){$GLOBALS['pushed']=true;if(getenv('REFRESH_CSRF_STORE')==='remote_failure')return false;}else{$GLOBALS['stored'][$params[0]]=$params[1];}return true; }
function db_fetch_assoc($sql) { return array(array('id'=>2,'last_polled'=>0)); }
function array_rekey($rows,$key,$value) { return array_column($rows,$value,$key); }
function is_remote_path_setting($name) { return false; }
function poller_connect_to_remote($id) { $GLOBALS['pushed']=true; return $GLOBALS['rotation_collector']; }
function raise_message(...$args) {}
function __($message,...$args) { return $message; }
define('MESSAGE_LEVEL_WARN',2);define('MESSAGE_LEVEL_ERROR',3);
function read_config_option($name, $force = false) { if($name==='poller_interval')return 300;return $GLOBALS['rotation_primary']->query('SELECT value FROM settings')->fetchColumn() ?: ''; }
require getenv('REFRESH_CSRF_ROOT') . '/tests/Fixtures/csrf-rotation-sqlite.php';
$rotation_primary = new CsrfRotationSqlite(getenv('REFRESH_CSRF_DIR').'/primary.sqlite', getenv('REFRESH_CSRF_DIR'), getenv('REFRESH_CSRF_DIR').'/rotation.lock');
$rotation_collector = new CsrfRotationSqlite(getenv('REFRESH_CSRF_DIR').'/collector.sqlite', getenv('REFRESH_CSRF_DIR').'-collector', getenv('REFRESH_CSRF_DIR').'/collector.lock');
$rotation_primary->exec("INSERT INTO poller VALUES(2,CURRENT_TIMESTAMP,'')");
if(getenv('REFRESH_CSRF_STORE')==='broken')$rotation_primary->exec("CREATE TRIGGER replace_stored_key AFTER INSERT ON settings BEGIN UPDATE settings SET value='' WHERE name='csrf_secret'; END");
if(getenv('REFRESH_CSRF_STORE')==='remote_failure')$rotation_collector->exec("CREATE TRIGGER reject_remote BEFORE INSERT ON settings BEGIN SELECT RAISE(FAIL,'fixture write rejection'); END");
$database_hostname='fixture';$database_port=0;$database_default='fixture';$database_sessions=array('fixture:0:fixture'=>$rotation_primary);
require getenv('REFRESH_CSRF_ROOT') . '/include/csrf.php';
register_shutdown_function(function () { echo 'PUSHED:' . (!empty($GLOBALS['pushed']) ? 'yes' : 'no') . ':STORED:' . strlen(read_config_option('csrf_secret')); $secret = getenv('REFRESH_CSRF_SECRET'); if ($secret === '' || !file_exists($secret) || file_get_contents($secret) !== false) { $GLOBALS['nativeChildCoverageMarkers'][] = 'rotation-store-file-readback'; } });
PHP;
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
    $bootstrap .= test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'set_config_option');
    file_put_contents($dir . '/include/cli_check.php', $bootstrap);

    $identical = hash_equals(hash_file('sha256', $root . '/cli/refresh_csrf.php'), hash_file('sha256', $dir . '/cli/refresh_csrf.php'));
    $registration = child_coverage_registration(
        __FILE__,
        $identical ? 'canonical-csrf-rotation' : 'injected-csrf-rotation-control',
        array($scenario, hash_file('sha256', $dir . '/cli/refresh_csrf.php'), hash('sha256', $bootstrap)),
        array('rotation-store-file-readback'),
        $identical ? array_merge(array('include/csrf.php', 'cli/refresh_csrf.php'), $secret === '' && empty($scenario['role']) ? array('lib/csrf_rotation.php') : array()) : array('include/csrf.php'),
        array('tests/Helpers/PhpSource.php', 'tests/Fixtures/csrf-rotation-sqlite.php')
    );
    if ($identical) {
        $registration['collectorPrelude'] = 'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($dir . '/cli/refresh_csrf.php', true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/cli/refresh_csrf.php', true) . ');';
    }
    $env = array(
        'REFRESH_CSRF_DIR' => $dir,
        'REFRESH_CSRF_ROOT' => $root,
        'REFRESH_CSRF_SECRET' => $secret,
        'REFRESH_CSRF_WEB_ROOT' => empty($scenario['unknown_root']) ? ($scenario['served_outside'] ?? false ? $outside : $dir) : '',
        'REFRESH_CSRF_STORE' => $scenario['store'] ?? 'ok',
        'REFRESH_CSRF_OS' => $scenario['os'] ?? 'unix',
        'REFRESH_CSRF_ROLE' => (string) ($scenario['role'] ?? 1),
    ) + getenv();

    try {
        $process = proc_open(
            child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', '$GLOBALS["cacti_csrf_rotation_worker"] = true; $_SERVER["argv"] = array("refresh_csrf.php"); require $argv[1];', $dir . '/cli/refresh_csrf.php'), $coverage_dir, $registration),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir,
            $env
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        child_coverage_collect($coverage_dir);

        return array(
            'fallback_removed' => !file_exists($dir . '/fallback-path') || !file_exists(trim(file_get_contents($dir . '/fallback-path'))),
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'legacy' => file_exists($dir . '/include/vendor/csrf/csrf-secret.php'),
            'mode' => $secret !== '' && file_exists($secret) ? (fileperms($secret) & 0777) : null,
            'secret' => $secret !== '' && file_exists($secret) ? file_get_contents($secret) : null,
        );
    } finally {
        foreach (array_merge(glob($dir . '/fallback-path'), glob($dir . '/*.coverage'), glob($dir . '/{cli,lib,include,include/vendor/csrf}/*.php', GLOB_BRACE), glob($outside . '/*'), glob($dir . '/*.php'), glob($dir . '/*.sqlite'), glob($dir . '/*.lock')) as $file) {
            unlink($file);
        }
        foreach (array('cli', 'lib', 'include/vendor/csrf', 'include/vendor', 'include') as $part) {
            rmdir($dir . '/' . $part);
        }
        rmdir($dir);
        rmdir($outside);
    }
}

test('without an external secret the stored secret is rotated and the old file removed', function () {
    $result = refresh_csrf_run($this, array('legacy' => true));

    expect($result['exit'])->toBe(0)
        ->and($result['stderr'])->toBe('')
        ->and($result['stdout'])->toContain('New CSRF secret stored in the database.')
        ->and($result['stdout'])->toContain('Removing old csrf_secret.php file.')
        ->and($result['stdout'])->toEndWith('STORED:64')
        ->and($result['stdout'])->toContain('PUSHED:yes')
        ->and($result['legacy'])->toBeFalse();
});

test('a secret the database does not keep is reported as a failure', function () {
    $result = refresh_csrf_run($this, array('store' => 'broken'));

    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toContain('FATAL: CSRF secret rotation could not be stored or propagated to every active collector.')
        ->and($result['stdout'])->toContain('STORED:0');
});

test('collector-local database rotation is refused before writing or propagating', function () {
    $result = refresh_csrf_run($this, array('role' => 2));
    expect($result['exit'])->toBe(1)->and($result['stderr'])->toBe('')
        ->and($result['stdout'])->toContain('Run database CSRF rotation on the primary Data Collector.')
        ->and($result['stdout'])->toEndWith('PUSHED:no:STORED:0');
});

test('collector-local external-file rotation remains available', function () {
    $result = refresh_csrf_run($this, array('role' => 2, 'secret' => '{outside}/csrf-secret.php', 'existing' => true));
    expect($result['exit'])->toBe(0)->and($result['stderr'])->toBe('')->and($result['mode'])->toBe(0640)
        ->and($result['secret'])->toMatch('/^<\?php \$secret = [\x22\x27][0-9a-f]{64}[\x22\x27];\n$/');
});

test('an external secret under the document root is refused', function () {
    $result = refresh_csrf_run($this, array('secret' => '{root}/include/csrf-secret.php'));

    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toContain('FATAL: The configured CSRF secret must be outside the Kadupul document root.')
        ->and($result['secret'])->toBeNull();
});

test('an external secret outside the document root is replaced', function (bool $existing, string $note) {
    $result = refresh_csrf_run($this, array('secret' => '{outside}/csrf-secret.php', 'existing' => $existing));

    expect($result['exit'])->toBe(0)
        ->and($result['stderr'])->toBe('')
                ->and($result['stdout'])->toContain('New csrf_secret.php file written.')
        ->and($result['stdout'])->toEndWith('STORED:0')
        ->and($result['mode'])->toBe(0640)
        ->and($result['secret'])->toMatch('/^<\?php \$secret = [\x22\x27][0-9a-f]{64}[\x22\x27];\n$/');
})->with(array(
    'existing file' => array(true, 'Removing old csrf_secret.php file.'),
    'missing file' => array(false, 'WARNING: csrf_secret.php file does not exist!'),
));

test('rotation skips unsupported or unchanged ownership changes', function (string $os) {
    $result = refresh_csrf_run($this, array('secret' => '{outside}/csrf-secret.php', 'existing' => true, 'ownership_refused' => true, 'os' => $os));
    expect($result['exit'])->toBe(0)->and($result['stderr'])->toBe('')
        ->and($result['mode'])->toBe(0640)->and($result['secret'])->not->toBe('<?php $secret = "old";');
})->with(array('unix', 'win32'));

test('atomic rotation preserves read-only secret modes after writing the replacement', function (int $mode) {
    $result = refresh_csrf_run($this, array('secret' => '{outside}/csrf-secret.php', 'existing' => true, 'mode' => $mode));
    expect($result['exit'])->toBe(0)->and($result['stderr'])->toBe('')
        ->and($result['mode'])->toBe($mode)
        ->and($result['secret'])->toMatch('/^<\?php \$secret = [\x22\x27][0-9a-f]{64}[\x22\x27];\n$/');
})->with(array(0400, 0440));

test('failed local settings persistence cannot update collectors or the active configuration cache', function (string $mode) {
    $directory = sys_get_temp_dir() . '/config-propagation-' . bin2hex(random_bytes(8));
    mkdir($directory . '/lib', 0700, true);
    file_put_contents($directory . '/lib/poller.php', '<?php');
    try {
        $program = 'require ' . var_export(dirname(__DIR__, 3) . '/Fixtures/config-propagation-native.php', true) . ';';
        $process = proc_open(child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $directory, $mode), $coverage_dir, child_coverage_registration(__FILE__, 'config-propagation', array($mode), array('config-propagation-readback'), array('lib/functions.php'), array('tests/Fixtures/config-propagation-native.php'))), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        child_coverage_collect($coverage_dir);
        expect(array($exit, $error))->toBe(array(0, ''));
        expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array('result' => false, 'connections' => 0, 'central' => 'old-central', 'collector' => 'old-collector', 'cache' => 'old-central'));
    } finally {
        unlink($directory . '/lib/poller.php');
        rmdir($directory . '/lib');
        rmdir($directory);
    }
})->with(array('web', 'cli'));

test('failed secret cleanup exits without reporting rotation success', function (string $path) {
    $result = refresh_csrf_run($this, array('secret' => $path, 'legacy' => true, 'existing' => $path !== '', 'unlink_failure' => true));
    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toContain('FATAL: Unable to remove')
        ->and($result['stdout'])->not->toContain('New CSRF secret stored')
        ->and($result['stdout'])->not->toContain('New csrf_secret.php file written');
})->with(array(''));


test('CLI rejects external secrets when the served root is unknown or contains the secret', function (array $scenario) {
    $result = refresh_csrf_run($this, $scenario + array('secret' => '{outside}/csrf-secret.php', 'existing' => true));
    expect($result['exit'])->toBe(1)->and($result['secret'])->toBe('<?php $secret = "old";');
})->with(array('unknown served root' => array(array('unknown_root' => true)), 'served alias root' => array(array('served_outside' => true))));

test('failed atomic rotation preserves the prior external secret', function (string $failure) {
    $result = refresh_csrf_run($this, array('secret' => '{outside}/csrf-secret.php', 'existing' => true, 'filesystem_failure' => $failure));
    expect($result['exit'])->toBe(1)->and($result['secret'])->toBe('<?php $secret = "old";')
        ->and($result['stderr'])->toBe('')->and($result['fallback_removed'])->toBeTrue()
        ->and($result['stdout'])->not->toContain('New csrf_secret.php file written');
})->with(array('short_write', 'readback', 'rename', 'temporary', 'fallback'));


test('collector propagation failure is not reported as rotation success', function () {
    $result = refresh_csrf_run($this, array('store' => 'remote_failure'));
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toContain('could not be stored or propagated')
        ->and($result['stdout'])->not->toContain('New CSRF secret stored');
});
