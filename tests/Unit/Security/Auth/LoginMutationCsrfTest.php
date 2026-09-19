<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('form login validates method and token before reading credentials', function ($authMethod, $method, $query, $token, $status) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/login-csrf-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("PROFILE_SECURITY_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-login-test-secret');
}
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
function read_config_option($key) { return $key === 'auth_method' ? $GLOBALS['argv'][2] : '0'; }
// Stop at the credential boundary: this test cannot log in or contact LDAP.
function auth_get_username() { echo 'CREDENTIAL_BOUNDARY:'; exit; }
session_id('login-csrf-test-session');
$_SERVER['REQUEST_METHOD'] = $argv[3];
parse_str($argv[4], $_REQUEST);
$_GET = $argv[3] === 'GET' ? $_REQUEST : array();
$_POST = $argv[3] === 'POST' ? $_REQUEST : array();
if ($argv[5] === 'valid') {
    $_POST['__csrf_magic'] = csrf_get_tokens();
} elseif ($argv[5] === 'invalid') {
    $_POST['__csrf_magic'] = 'invalid';
} elseif ($argv[5] === 'query') {
    $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
}
register_shutdown_function(function () { echo http_response_code() ?: 200; });
require $argv[1] . '/auth_login.php';
PHP;
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . $program, $root, $authMethod, $method, $query, $token),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)
            ->and($stderr)->toBe('')
            ->and($stdout)->toBe(($status === 200 ? 'CREDENTIAL_BOUNDARY:' : '') . $status);
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
})->with(array(
    'local GET' => array('1', 'GET', 'action=login', 'missing', 405),
    'LDAP GET' => array('3', 'GET', 'action=login', 'missing', 405),
    'domains GET' => array('4', 'GET', 'action=login', 'missing', 405),
    'array GET' => array('1', 'GET', 'action[]=login', 'missing', 400),
    'nested GET' => array('1', 'GET', 'action[x][]=login', 'missing', 400),
    'array POST' => array('1', 'POST', 'action[]=login', 'valid', 400),
    'HEAD' => array('1', 'HEAD', 'action=login', 'missing', 405),
    'PUT' => array('1', 'PUT', 'action=login', 'valid', 405),
    'missing token' => array('1', 'POST', 'action=login', 'missing', 403),
    'invalid token' => array('1', 'POST', 'action=login', 'invalid', 403),
    'query token' => array('1', 'POST', 'action=login', 'query', 403),
    'valid local POST' => array('1', 'POST', 'action=login', 'valid', 200),
    'valid LDAP POST' => array('3', 'POST', 'action=login', 'valid', 200),
    'valid domains POST' => array('4', 'POST', 'action=login', 'valid', 200),
    'login page GET' => array('1', 'GET', '', 'missing', 200),
    'server Basic GET' => array('2', 'GET', '', 'missing', 200),
    'server Basic explicit action' => array('2', 'GET', 'action=login', 'missing', 200),
));
