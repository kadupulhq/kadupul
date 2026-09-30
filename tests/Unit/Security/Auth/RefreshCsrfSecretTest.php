<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * cli/refresh_csrf.php must rotate the secret web requests read: the stored
 * one when no $path_csrf_secret is set, otherwise the external file, which it
 * must refuse to write under the document root. An exact copy of the script
 * runs against an isolated bootstrap and the shipped include/csrf.php.
 */

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
    file_put_contents($dir . '/lib/poller.php', '<?php');
    file_put_contents($dir . '/lib/utility.php', '<?php');
    file_put_contents($dir . '/include/vendor/csrf/csrf-conf.php', '<?php');
    file_put_contents($dir . '/include/vendor/csrf/csrf-magic.php', '<?php function csrf_writable($file) { return is_writable(file_exists($file) ? $file : dirname($file)); }');
    if (!empty($scenario['legacy'])) {
        file_put_contents($dir . '/include/vendor/csrf/csrf-secret.php', '<?php $secret = "legacy";');
    }
    $secret = str_replace(array('{outside}', '{root}'), array($outside, $dir), $scenario['secret'] ?? '');
    if (!empty($scenario['existing'])) {
        file_put_contents($secret, '<?php $secret = "old";');
    }

    $bootstrap = <<<'PHP'
<?php
$config = array('base_path' => getenv('REFRESH_CSRF_DIR'), 'include_path' => getenv('REFRESH_CSRF_DIR') . '/include', 'is_web' => false);
if (getenv('REFRESH_CSRF_SECRET') !== '') {
    $config['path_csrf_secret'] = getenv('REFRESH_CSRF_SECRET');
}
$GLOBALS['stored'] = array();
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function set_config_option($name, $value) { $GLOBALS['stored'][$name] = $value; }
function read_config_option($name, $force = false) { return getenv('REFRESH_CSRF_STORE') === 'broken' ? '' : ($GLOBALS['stored'][$name] ?? ''); }
require getenv('REFRESH_CSRF_ROOT') . '/include/csrf.php';
register_shutdown_function(function () { echo 'STORED:' . (isset($GLOBALS['stored']['csrf_secret']) ? strlen($GLOBALS['stored']['csrf_secret']) : 0); });
PHP;
    file_put_contents($dir . '/include/cli_check.php', $bootstrap);

    $coverage = $test->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("INSTALLER_CSRF_BOOTSTRAP_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($dir . '/cli/refresh_csrf.php', true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/cli/refresh_csrf.php', true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $env = array(
        'REFRESH_CSRF_DIR' => $dir,
        'REFRESH_CSRF_ROOT' => $root,
        'REFRESH_CSRF_SECRET' => $secret,
        'REFRESH_CSRF_STORE' => $scenario['store'] ?? 'ok',
    ) + getenv();

    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . '$_SERVER["argv"] = array("refresh_csrf.php"); require $argv[1];', $dir . '/cli/refresh_csrf.php'),
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
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }

        return array(
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'legacy' => file_exists($dir . '/include/vendor/csrf/csrf-secret.php'),
            'secret' => $secret !== '' && file_exists($secret) ? file_get_contents($secret) : null,
        );
    } finally {
        foreach (array_merge(glob($dir . '/*.coverage'), glob($dir . '/{cli,lib,include,include/vendor/csrf}/*.php', GLOB_BRACE), glob($outside . '/*'), glob($dir . '/*.php')) as $file) {
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
        ->and($result['legacy'])->toBeFalse();
});

test('a secret the database does not keep is reported as a failure', function () {
    $result = refresh_csrf_run($this, array('store' => 'broken'));

    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toContain('FATAL: Unable to store the new CSRF secret in the database.');
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
        ->and($result['stdout'])->toContain($note)
        ->and($result['stdout'])->toContain('New csrf_secret.php file written.')
        ->and($result['stdout'])->toEndWith('STORED:0')
        ->and($result['secret'])->toMatch('/^<\?php \$secret = "[0-9a-f]{64}";\n$/');
})->with(array(
    'existing file' => array(true, 'Removing old csrf_secret.php file.'),
    'missing file' => array(false, 'WARNING: csrf_secret.php file does not exist!'),
));
