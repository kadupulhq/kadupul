<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('account administration rejects unprotected mutation requests', function ($controller, $route, $method, $token, $expected) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/admin-csrf-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    file_put_contents($dir . '/include/auth.php', '<?php');
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-admin-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/global_constants.php';
function __($value) { return $value; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_execute_prepared(...$args) { echo 'WRITE'; exit; }
function sanitize_unserialize_selected_items($value) { echo 'WRITE'; exit; }
session_id('admin-csrf-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[4];
$_REQUEST = array('id' => '2', 'user_id' => '2', 'group_id' => '2', 'type' => 'graph', 'policy_graphs' => '1', 'selected_items' => 'fixture', 'drp_action' => '1');
if ($argv[3] === 'cached_policy') {
    $_CACTI_REQUEST['update_policy'] = '1';
} elseif ($argv[3] === 'update_policy') {
    $_REQUEST['update_policy'] = '1';
} else {
    $_REQUEST['action'] = $argv[3];
}
if ($argv[5] === 'action_array') $_REQUEST['action'] = array('save');
$_POST = $argv[4] === 'POST' ? $_REQUEST : array();
$_GET = $argv[4] === 'GET' ? $_REQUEST : array();
if ($argv[5] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[5] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
if ($argv[5] === 'array') $_POST['__csrf_magic'] = array('bad');
if ($argv[5] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $argv[1] . '/' . $argv[2];
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("ADMIN_MUTATION_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root, $controller, $route, $method, $token), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($stderr . $stdout);
        }
        expect($stderr)->toBe('');
        expect($stdout)->toBe(($expected === 200 ? 'WRITE' : '') . 'STATUS:' . $expected);
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }
    } finally {
        unlink($dir . '/include/auth.php');
        rmdir($dir . '/include');
        foreach (glob($dir . '/*.coverage') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array('user_admin.php', 'user_group_admin.php'))
    ->with(array('update_policy', 'cached_policy', 'perm_remove', 'actions'))
    ->with(array(
        array('GET', 'missing', 405),
        array('GET', 'valid', 405),
        array('HEAD', 'missing', 405),
        array('PUT', 'missing', 405),
        array('', 'missing', 405),
        array('GET', 'action_array', 400),
        array('POST', 'action_array', 400),
        array('POST', 'missing', 403),
        array('POST', 'query', 403),
        array('POST', 'array', 403),
        array('POST', 'forged', 403),
        array('POST', 'valid', 200),
    ));
