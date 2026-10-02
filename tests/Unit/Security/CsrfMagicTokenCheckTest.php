<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// A native completion receipt binds the child producer, scenario and worker bytes.
function verifyCsrfCallbackReceipt(array $receipt, array $expected): void
{
    if (($receipt['marker'] ?? null) !== 'native configured callback completed'
        || ($receipt['php'] ?? null) !== PHP_VERSION
        || ($receipt['sources'] ?? null) !== $expected) {
        throw new RuntimeException('Incomplete or stale configured callback evidence');
    }
}

// Loads the installed csrf-magic.php in a child process with a debug log
// directory and runs $body there; returns what it printed and the log text.
function runCsrfMagicProbe(string $body, bool $configured = false, $coverage = null): array
{
    $directory = sys_get_temp_dir() . '/csrf-magic-probe-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('frame-breaker', false);
    csrf_conf('secret', 'probe-secret-0123456789abcdef0123456789');
    csrf_conf('log_file', $GLOBALS['argv'][2] . '/csrf.log');
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/kadupul/graphs.php?probe-query-value';
$_GET = array('probe-get-key' => 'probe-get-value');
$_POST = array('probe-post-key' => array('probe-post-value'));
session_id('probe-session');
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
PHP;
    if ($configured) {
        $program = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/functions.php';
require $argv[1] . '/lib/html_utility.php';
function __($message) { return $message; }
$messages = array('csrf_timeout' => array('message' => 'Session expired', 'type' => 'error'));
$config = array('base_path' => $argv[1], 'include_path' => $argv[1] . '/include', 'url_path' => '/kadupul/', 'is_web' => true, 'path_csrf_secret' => $argv[2] . '/owned-secret.php');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/kadupul/graphs.php?probe-query-value';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['SERVER_PORT'] = 80;
$_GET = array('probe-get-key' => 'probe-get-value');
$_POST = array();
session_start(array('save_path' => $argv[2], 'use_cookies' => 0));
require $argv[1] . '/include/csrf.php';
csrf_conf('log_file', $argv[2] . '/csrf.log');
PHP;
    }
    $expectedSources = array();
    if ($coverage !== null) {
        foreach (array('tests/Fixtures/rrd-process-coverage.php', 'tests/Unit/Security/CsrfMagicTokenCheckTest.php', 'include/csrf.php', 'include/vendor/csrf/csrf-magic.php') as $relative) {
            $expectedSources[$relative] = hash_file('sha256', dirname(__DIR__, 3) . '/' . $relative);
        }
        $program = <<<'PHP'
define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
define('CSRF_CALLBACK_TEST_COVERAGE', 1);
require $argv[1] . '/tests/Fixtures/rrd-process-coverage.php';
register_shutdown_function(function () {
    $sources = array();
    foreach (array('tests/Fixtures/rrd-process-coverage.php', 'tests/Unit/Security/CsrfMagicTokenCheckTest.php', 'include/csrf.php', 'include/vendor/csrf/csrf-magic.php') as $relative) {
        $sources[$relative] = hash_file('sha256', $GLOBALS['argv'][1] . '/' . $relative);
    }
    $completed = ($GLOBALS['csrf']['callback'] ?? null) === 'csrf_error_callback'
        && ($_SESSION['sess_messages']['csrf_timeout']['message'] ?? null) === 'Session expired';
    file_put_contents($GLOBALS['argv'][2] . '/callback-evidence.json', json_encode(array('marker' => $completed ? 'native configured callback completed' : 'incomplete', 'php' => PHP_VERSION, 'sources' => $sources), JSON_THROW_ON_ERROR));
});
PHP . $program;
    }

    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', '-d', 'pcov.directory=/', '-r', $program . $body, dirname(__DIR__, 3), $directory),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)
            ->and($stderr)->toBe('');

        $log = '';
        foreach (glob($directory . '/*.log') as $file) {
            $log .= file_get_contents($file);
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $receipt = json_decode(file_get_contents($directory . '/callback-evidence.json'), true, flags: JSON_THROW_ON_ERROR);
            verifyCsrfCallbackReceipt($receipt, $expectedSources);
            $childCoverage = unserialize(file_get_contents($reports[0]));
            $observed = $childCoverage->getData()->lineCoverage();
            $source = dirname(__DIR__, 3) . '/include/csrf.php';
            expect(isset($observed[$source]))->toBeTrue();
            expect(count(array_filter($observed[$source], static fn($hits) => !empty($hits))) > 0)->toBeTrue();
            $coverage->merge($childCoverage);
        }

        return array($stdout, $log);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

test('token collections and timestamps are bounded before hashing', function () {
    [$stdout] = runCsrfMagicProbe(<<<'PHP'
$valid = 'sid:' . csrf_hash(session_id());
$future = 'sid:' . csrf_hash(session_id(), time() + 3600);
echo json_encode(array(
    'valid' => csrf_check_tokens($valid),
    'valid among eight' => csrf_check_tokens(str_repeat('sid:x,1;', 7) . $valid),
    'valid among nine' => csrf_check_tokens(str_repeat('sid:x,1;', 8) . $valid),
    'non-string entry' => csrf_check_tokens(array(array(), $valid)),
    'future time' => csrf_check_tokens($future),
    'non-numeric time' => csrf_check_tokens('sid:abc,soon'),
    'empty time' => csrf_check_tokens('sid:abc,'),
));
PHP);

    expect(json_decode($stdout, true))->toBe(array(
        'valid' => true,
        'valid among eight' => true,
        'valid among nine' => false,
        'non-string entry' => true,
        'future time' => false,
        'non-numeric time' => false,
        'empty time' => false,
    ));
});

test('generated secrets come from random_bytes', function () {
    [$stdout] = runCsrfMagicProbe(<<<'PHP'
$a = csrf_generate_secret();
$b = csrf_generate_secret();
try {
    csrf_generate_secret(8);
    $short = 'accepted';
} catch (InvalidArgumentException $e) {
    $short = 'refused';
}
echo json_encode(array(preg_match('/\A[0-9a-f]{64}\z/', $a), $a !== $b, $short));
PHP);

    expect(json_decode($stdout, true))->toBe(array(1, true, 'refused'));
});

test('the debug log records no secret, token, form data or query string', function () {
    [$stdout, $log] = runCsrfMagicProbe(<<<'PHP'
$token = csrf_get_tokens();
csrf_check_tokens($token);
csrf_flattenpost($_POST);
csrf_ob_handler('<html><body><form method="post"></form></body></html>', 0);
ob_start();
csrf_callback($token);
$page = ob_get_clean();
echo json_encode(array('hash' => explode(',', substr($token, 4))[0], 'page' => $page));
PHP);
    $result = json_decode($stdout, true);

    expect($log)->toContain('issued token type: sid')
        ->and($log)->toContain('post_fields=1; get_fields=1')
        ->and($log)->not->toContain($result['hash'])
        ->and($log)->not->toContain('probe-secret-0123456789abcdef0123456789')
        ->and($log)->not->toContain('probe-post-value')
        ->and($log)->not->toContain('probe-get-value')
        ->and($log)->not->toContain('probe-query-value')
        ->and($result['page'])->not->toContain($result['hash'])
        ->and($result['page'])->not->toContain('probe-post-value');
});


test('secret publication is atomic and preserves existing files on refusal', function () {
    [$stdout] = runCsrfMagicProbe(<<<'PHP'
$path = $argv[2] . '/secret.php';
$prior_umask = umask();
$old = '<?php $secret = "working-key";' . PHP_EOL;
file_put_contents($path, $old);
$invalid = csrf_write_secret($path, 'invalid-secret');
$preserved = file_get_contents($path) === $old;
$secret = csrf_generate_secret();
$written = csrf_write_secret($path, $secret);
$expected = '<?php $secret = ' . var_export($secret, true) . ';' . PHP_EOL;
$bytes = file_get_contents($path) === $expected;
$mode = fileperms($path) & 0777;
$link = $argv[2] . '/alias.php';
symlink($path, $link);
$alias = csrf_write_secret($link, csrf_generate_secret());
$unchanged = is_link($link) && file_get_contents($path) === $expected;
$missing = csrf_write_secret($argv[2] . '/missing/secret.php', csrf_generate_secret());
$restored_umask = umask() === $prior_umask;
$temporary = glob($argv[2] . '/.csrf-secret-*');
echo json_encode(array($invalid, $preserved, $written, $bytes, $mode, $alias, $unchanged, $missing, $temporary, $restored_umask));
PHP);
    expect(json_decode($stdout, true))->toBe(array(false, true, true, true, 0640, false, true, false, array(), true));
});


test('the complete rotation CLI preserves the working secret when generation throws', function () {
    require_once dirname(__DIR__, 3) . '/tests/Helpers/PhpSource.php';
    $root = sys_get_temp_dir() . '/csrf-rotation-cli-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/cli', 0700);
    mkdir($root . '/include', 0700);
    mkdir($root . '/lib', 0700);
    $source = file_get_contents(dirname(__DIR__, 3) . '/cli/refresh_csrf.php');
    expect($source)->toBeString();
    $old = '<?php $secret = "working-fixture-secret";' . PHP_EOL;
    try {
        // Copy the complete CLI unchanged; only bootstrap dependencies supply
        // the generation failure. No rotation logic is copied into the fixture.
        file_put_contents($root . '/cli/refresh_csrf.php', $source);
        file_put_contents($root . '/working.php', $old);
        file_put_contents($root . '/lib/poller.php', '<?php');
        file_put_contents($root . '/lib/utility.php', '<?php');
        file_put_contents($root . '/include/cli_check.php', <<<'PHP'
<?php
$config = array('base_path' => dirname(__DIR__), 'path_csrf_secret' => dirname(__DIR__) . '/working.php');
function cacti_sizeof($items) { return count($items); }
function csrf_generate_secret() { throw new RuntimeException('Simulated entropy failure'); }
PHP);
        $result = test_php_run(array(PHP_BINARY, $root . '/cli/refresh_csrf.php'));
        expect(file_exists($root . '/working.php'))->toBeTrue()
            ->and($result['status'])->toBe(1)
            ->and($result['err'])->toBe('')
            ->and($result['out'])->toContain('FATAL: Unable to generate a new CSRF secret.')
            ->and($result['out'])->not->toContain('working-fixture-secret')
            ->and(file_get_contents($root . '/working.php'))->toBe($old)
            ->and(hash_file('sha256', $root . '/cli/refresh_csrf.php'))->toBe(hash('sha256', $source));
    } finally {
        foreach (array('/cli/refresh_csrf.php', '/include/cli_check.php', '/lib/poller.php', '/lib/utility.php', '/working.php') as $file) {
            if (file_exists($root . $file)) {
                unlink($root . $file);
            }
        }
        foreach (array('/cli', '/include', '/lib', '') as $directory) {
            rmdir($root . $directory);
        }
    }
});


test('the complete rotation CLI uses the installed publisher and reports its result', function (bool $blocked) {
    require_once dirname(__DIR__, 3) . '/tests/Helpers/PhpSource.php';
    $repository = dirname(__DIR__, 3);
    $root = sys_get_temp_dir() . '/csrf-rotation-result-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    foreach (array('/cli', '/include', '/lib', '/keys') as $directory) {
        mkdir($root . $directory, 0700);
    }
    $old = '<?php $secret = "working-fixture-secret";' . PHP_EOL;
    try {
        $source = file_get_contents($repository . '/cli/refresh_csrf.php');
        expect($source)->toBeString();
        file_put_contents($root . '/cli/refresh_csrf.php', $source);
        file_put_contents($root . '/keys/working.php', $old);
        file_put_contents($root . '/lib/poller.php', '<?php');
        file_put_contents($root . '/lib/utility.php', '<?php');
        $bootstrap = <<<'PHP'
<?php
$config = array('base_path' => dirname(__DIR__), 'path_csrf_secret' => dirname(__DIR__) . '/keys/working.php');
function cacti_sizeof($items) { return count($items); }
function csrf_startup() { csrf_conf('disable', true); csrf_conf('rewrite', false); }
PHP;
        $bootstrap .= PHP_EOL . 'require ' . var_export($repository . '/include/vendor/csrf/csrf-magic.php', true) . ';';
        file_put_contents($root . '/include/cli_check.php', $bootstrap);
        if ($blocked) {
            chmod($root . '/keys', 0500);
            if (is_writable($root . '/keys')) {
                test()->markTestSkipped('This host bypasses directory mode restrictions; exclusive-create refusal cannot be exercised');
            }
        }
        $result = test_php_run(array(PHP_BINARY, $root . '/cli/refresh_csrf.php'));
        expect($result['status'])->toBe($blocked ? 1 : 0)
            ->and($result['err'])->toBe('')
            ->and($result['out'])->not->toContain('working-fixture-secret')
            ->and(glob($root . '/keys/.csrf-secret-*'))->toBe(array());
        $contents = file_get_contents($root . '/keys/working.php');
        if ($blocked) {
            expect($result['out'])->toContain('FATAL: Unable to write new csrf_secret.php file.')
                ->and($contents)->toBe($old);
        } else {
            expect($result['out'])->toContain('NOTE: New csrf_secret.php file written.')
                ->and($contents)->not->toBe($old)
                ->and(preg_match('/\A<\?php \$secret = \'[0-9a-f]{64}\';\R\z/', $contents))->toBe(1)
                ->and(fileperms($root . '/keys/working.php') & 0777)->toBe(0640);
        }
    } finally {
        chmod($root . '/keys', 0700);
        foreach (array('/cli/refresh_csrf.php', '/include/cli_check.php', '/lib/poller.php', '/lib/utility.php', '/keys/working.php') as $file) {
            if (file_exists($root . $file)) {
                unlink($root . $file);
            }
        }
        foreach (array('/cli', '/include', '/lib', '/keys', '') as $directory) {
            rmdir($root . $directory);
        }
    }
})->with(array('successful rotation' => array(false), 'unwritable publication directory' => array(true)));

test('atomic rotation retains an existing reader ownership', function () {
    [$stdout] = runCsrfMagicProbe(<<<'PHP'
$path = $argv[2] . '/ownership.php';
file_put_contents($path, '<?php $secret = "old-owned-key";');
$group = filegroup($path);
$alternate = null;
foreach (posix_getgroups() as $candidate) {
    if ($candidate !== $group) { $alternate = $candidate; break; }
}
if (posix_geteuid() === 0) {
    chown($path, 65534);
    $alternate = 65534;
}
if ($alternate === null || !@chgrp($path, $alternate)) {
    echo json_encode(array('unsupported ownership setup'));
} else {
    clearstatcache(true, $path);
    $owner = fileowner($path);
    $group = filegroup($path);
    $written = csrf_write_secret($path, csrf_generate_secret());
    clearstatcache(true, $path);
    echo json_encode(array($written, fileowner($path) === $owner, filegroup($path) === $group, glob($argv[2] . '/.csrf-secret-*')));
}
PHP);
    $result = json_decode($stdout, true);
    if ($result === array('unsupported ownership setup')) {
        test()->markTestSkipped('The host cannot create an alternate reader ownership fixture');
    }
    expect($result)->toBe(array(true, true, true, array()));
});

test('configured failure callback redacts query values from the native log', function () {
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    [$stdout, $log] = runCsrfMagicProbe(<<<'PHP'
if ($GLOBALS['csrf']['callback'] !== 'csrf_error_callback') {
    throw new RuntimeException('Native startup did not configure its failure callback');
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('__csrf_magic' => 'invalid-fixture-token');
csrf_check();
PHP, true, $coverage);
    expect($stdout)->toBe('')
        ->and($log)->toContain('Timeout, redirecting to /kadupul/graphs.php')
        ->and($log)->not->toContain('probe-query-value');
});


test('unavailable reader ownership preserves the old key and removes only the owned temporary', function () {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        test()->markTestSkipped('A privileged fixture is needed to create ownership this publisher cannot apply');
    }
    [$stdout] = runCsrfMagicProbe(<<<'PHP'
$path = $argv[2] . '/unavailable-owner.php';
$old = '<?php $secret = "working-owned-key";';
file_put_contents($path, $old);
chmod($path, 0666);
chmod($argv[2], 0777);
if (!posix_setgid(65534) || !posix_setuid(65534)) {
    throw new RuntimeException('Could not enter the owned unprivileged fixture');
}
$result = csrf_write_secret($path, csrf_generate_secret());
echo json_encode(array($result, file_get_contents($path) === $old, glob($argv[2] . '/.csrf-secret-*')));
PHP);
    expect(json_decode($stdout, true))->toBe(array(false, true, array()));
});


test('configured callback coverage refuses omitted or stale producer and source evidence', function () {
    $expected = array('producer' => 'a', 'scenario' => 'b', 'worker' => 'c', 'installed-library' => 'd');
    $valid = array('marker' => 'native configured callback completed', 'php' => PHP_VERSION, 'sources' => $expected);
    verifyCsrfCallbackReceipt($valid, $expected);
    foreach (array_keys($expected) as $source) {
        $omitted = $valid;
        unset($omitted['sources'][$source]);
        expect(fn() => verifyCsrfCallbackReceipt($omitted, $expected))->toThrow(RuntimeException::class);
        $stale = $valid;
        $stale['sources'][$source] = 'stale';
        expect(fn() => verifyCsrfCallbackReceipt($stale, $expected))->toThrow(RuntimeException::class);
    }
    foreach (array(array('marker' => 'incomplete'), array('php' => 'incorrect'), array('sources' => array())) as $changed) {
        expect(fn() => verifyCsrfCallbackReceipt(array_replace($valid, $changed), $expected))->toThrow(RuntimeException::class);
    }
});


require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';
eval(<<<'SWAP_ADAPTER'
namespace CsrfSecretSwap;
use Throwable;
function fwrite($stream, $bytes) {
    $written = \fwrite($stream, $bytes);
    if ($GLOBALS['csrf_swap_stage'] === 'write') {
        swap_path(\stream_get_meta_data($stream)['uri']);
    }
    return $GLOBALS['csrf_swap_failure'] ? 0 : $written;
}
function chmod($path, $mode) {
    $result = \chmod($path, $mode);
    if ($GLOBALS['csrf_swap_stage'] === 'metadata') { swap_path($path); }
    return $result;
}
function swap_path($temporary) {
    \rename($temporary, $GLOBALS['csrf_swap_root'] . '/displaced-owned');
    if ($GLOBALS['csrf_swap_kind'] === 'symlink') {
        \symlink($GLOBALS['csrf_swap_root'] . '/foreign', $temporary);
    } else {
        \file_put_contents($temporary, 'foreign replacement');
        \chmod($temporary, 0600);
    }
    $GLOBALS['csrf_swap_path'] = $temporary;
}
SWAP_ADAPTER);
$installedCsrfSource = file_get_contents(dirname(__DIR__, 3) . '/include/vendor/csrf/csrf-magic.php');
expect($installedCsrfSource)->toBeString();
eval('namespace CsrfSecretSwap; use Throwable; ' . test_php_function_source($installedCsrfSource, 'csrf_write_secret'));

test('secret pathname substitution preserves the key and caller-owned replacements', function (string $kind, bool $writeFailure, string $stage) {
    $root = sys_get_temp_dir() . '/csrf-secret-swap-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $destination = $root . '/secret.php';
    file_put_contents($destination, 'working secret');
    file_put_contents($root . '/foreign', 'foreign target');
    chmod($root . '/foreign', 0600);
    $GLOBALS['csrf_swap_root'] = $root;
    $GLOBALS['csrf_swap_kind'] = $kind;
    $GLOBALS['csrf_swap_failure'] = $writeFailure;
    $GLOBALS['csrf_swap_stage'] = $stage;
    try {
        $result = CsrfSecretSwap\csrf_write_secret($destination, str_repeat('a', 64));
        $temporary = $GLOBALS['csrf_swap_path'];
        expect($result)->toBeFalse()
            ->and(file_get_contents($destination))->toBe('working secret')
            ->and(file_get_contents($root . '/foreign'))->toBe('foreign target')
            ->and(fileperms($root . '/foreign') & 0777)->toBe(0600)
            ->and(file_exists($temporary))->toBeTrue();
        if ($kind === 'symlink') {
            expect(is_link($temporary))->toBeTrue();
        } else {
            expect(file_get_contents($temporary))->toBe('foreign replacement')
                ->and(fileperms($temporary) & 0777)->toBe(0600);
        }
        expect(file_get_contents($root . '/displaced-owned'))->toContain(str_repeat('a', 64));
    } finally {
        foreach (glob($root . '/*') as $path) {
            unlink($path);
        }
        foreach (glob($root . '/.csrf-secret-*') as $path) {
            unlink($path);
        }
        rmdir($root);
        foreach (['root', 'kind', 'failure', 'path', 'stage'] as $key) {
            unset($GLOBALS['csrf_swap_' . $key]);
        }
    }
})->with([
    'file before publication' => ['file', false, 'write'],
    'symlink before publication' => ['symlink', false, 'write'],
    'file before failed-write cleanup' => ['file', true, 'write'],
    'symlink before failed-write cleanup' => ['symlink', true, 'write'],
    'file after metadata before rename' => ['file', false, 'metadata'],
    'symlink after metadata before rename' => ['symlink', false, 'metadata'],
]);
