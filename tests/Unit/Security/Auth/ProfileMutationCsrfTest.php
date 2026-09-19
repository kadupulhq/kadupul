<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('profile mutations require unambiguous actions and a valid POST token', function ($action, $method, $shape, $token, $status) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/profile-csrf-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    // Isolate authentication/database setup, not the controller or CSRF validator.
    file_put_contents($dir . '/include/auth.php', '<?php');
    file_put_contents($dir . '/include/global.php', '<?php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("PROFILE_SECURITY_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program = <<<'PHP'
$root = $argv[1];
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-test-secret');
}
require $root . '/include/vendor/csrf/csrf-magic.php';
require $root . '/lib/html_utility.php';
function read_config_option($key) { return '0'; }
function db_execute_prepared(...$args) { echo 'MUTATION'; exit; }
function db_fetch_row_prepared(...$args) { return array('id' => 42, 'realm' => 0, 'password_change' => 'on', 'password' => '', 'username' => 'test'); }
function get_cacti_version() { return 'test'; }
function cacti_sizeof($value) { return count($value); }
function get_guest_account() { return 0; }
function secpass_check_pass($value) { return 'ok'; }
function secpass_check_history(...$args) { return true; }
function compat_password_verify(...$args) { return false; }
function get_client_addr() { return '127.0.0.1'; }
$config = array('url_path' => '/');
session_id('profile-csrf-test-session');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[3];
$field = $argv[4] === 'scalar' ? 'action' : ($argv[4] === 'array' ? 'action[]' : 'action[x][]');
parse_str($field . '=' . $argv[2] . '&tab=general&name=full_name&value=test&full_name=test&email_address=test&password=test&password_confirm=test', $_REQUEST);
$_GET = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_REQUEST : array();
$_POST = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_REQUEST : array();
if ($argv[5] === 'valid') {
    $_POST['__csrf_magic'] = csrf_get_tokens();
} elseif ($argv[5] === 'forged') {
    $_POST['__csrf_magic'] = 'sid:forged,1';
} elseif ($argv[5] === 'query') {
    $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
} elseif ($argv[5] === 'array') {
    $_POST['__csrf_magic'] = array('invalid');
}
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $root . ($argv[2] === 'changepassword' ? '/auth_changepassword.php' : '/auth_profile.php');
PHP;
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . $program, $root, $action, $method, $shape, $token),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $stderr . $stdout)
            ->and($stderr)->toBe('')
            ->and($stdout)->toBe(($status === 200 ? 'MUTATION' : '') . 'STATUS:' . $status);
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        unlink($dir . '/include/auth.php');
        unlink($dir . '/include/global.php');
        rmdir($dir . '/include');
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array('save', 'update_data', 'clear_user_settings', 'reset_default', 'logout_everywhere', 'changepassword'))
    ->with(array(
        'GET' => array('GET', 'scalar', 'missing', 405),
        'GET with body token' => array('GET', 'scalar', 'valid', 405),
        'HEAD' => array('HEAD', 'scalar', 'missing', 405),
        'PUT' => array('PUT', 'scalar', 'valid', 405),
        'missing method' => array('', 'scalar', 'missing', 405),
        'array GET' => array('GET', 'array', 'missing', 400),
        'nested array GET' => array('GET', 'nested', 'missing', 400),
        'array POST' => array('POST', 'array', 'valid', 400),
        'missing token' => array('POST', 'scalar', 'missing', 403),
        'forged token' => array('POST', 'scalar', 'forged', 403),
        'query token' => array('POST', 'scalar', 'query', 403),
        'array token' => array('POST', 'scalar', 'array', 403),
        'valid POST' => array('POST', 'scalar', 'valid', 200),
    ));

test('profile browser mutations send explicit POST tokens', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/auth_profile.php');
    foreach (array('clear_user_settings', 'reset_default', 'logout_everywhere') as $action) {
        expect($source)->toMatch('/\$\.post\(\x27auth_profile.php\x27, \{action: \x27' . $action . '\x27,[^}]*__csrf_magic: csrfMagicToken/');
    }
});

test('default action never coerces arrays into executable actions', function ($query, $expected) {
    $root = dirname(__DIR__, 4);
    $program = <<<'PHP'
require $argv[1] . '/lib/html_utility.php';
parse_str($argv[2], $_REQUEST);
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
set_default_action('view');
echo $_REQUEST['action'] . ':';
PHP;
    $process = proc_open(array(PHP_BINARY, '-r', $program, $root, $query), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('')->and($stdout)->toBe($expected);
})->with(array(
    array('action[]=save', 'STATUS:400'),
    array('action[x][]=update_data', 'STATUS:400'),
    array('action[99]=save', 'STATUS:400'),
    array('action=save', 'save:STATUS:200'),
    array('', 'view:STATUS:200'),
));

test('global bootstrap checks action shape and generic mutations before controller dispatch', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');
    expect($source)->toContain("cacti_require_post_actions(array('save', 'update_data', 'changepassword'));");
});
