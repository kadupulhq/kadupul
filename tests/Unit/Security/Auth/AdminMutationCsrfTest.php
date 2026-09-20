<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('plugin lifecycle redirects preserve AJAX query parameters', function () {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/plugin-redirect-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    file_put_contents($dir . '/include/auth.php', '<?php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $program = '<?php $root = ' . var_export($root, true) . ';';
    if ($coverage !== null) {
        $program .= 'define("ADMIN_MUTATION_TEST_COVERAGE", true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__);'
            . 'require $root . "/tests/Fixtures/rrd-process-coverage.php";';
    }
    $program .= <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-plugin-redirect-secret');
}
require $root . '/include/vendor/csrf/csrf-magic.php';
require $root . '/lib/html_utility.php';
require $root . '/include/global_constants.php';
function __($value) { return $value; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_execute_prepared(...$args) { header('X-Test-Mutation: remote'); }
function db_fetch_assoc($sql) { return array(array('directory' => 'fixture')); }
function sanitize_search_string($value) { return $value; }
function api_plugin_install($id) { header('X-Test-Mutation: install'); }
function api_plugin_uninstall($id) { header('X-Test-Mutation: uninstall'); }
function api_plugin_enable($id) { header('X-Test-Mutation: enable'); }
function api_plugin_disable($id) { header('X-Test-Mutation: disable'); }
function api_plugin_moveup($id) { header('X-Test-Mutation: moveup'); }
function api_plugin_movedown($id) { header('X-Test-Mutation: movedown'); }
$config = array('poller_id' => 2);
$plugins_integrated = array();
session_id('plugin-redirect-test');
$_SESSION = array('sess_user_id' => 42, 'sess_plugins_state' => (int) $_POST['test_state']);
// This fixture tests redirects; the separate request matrix tests token rejection.
$_POST['__csrf_magic'] = csrf_get_tokens();
require $root . '/plugins.php';
PHP;
    file_put_contents($dir . '/router.php', $program);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = null;
    try {
        // Coverage hooks and JIT are incompatible in the HTTP-server SAPI.
        $server = proc_open(
            array(PHP_BINARY, '-d', 'opcache.jit=off', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-S', $address, 'router.php'),
            array(0 => array('pipe', 'r'), 1 => array('file', $dir . '/server.log', 'a'),
                2 => array('file', $dir . '/server.log', 'a')),
            $pipes,
            $dir
        );
        if (!is_resource($server)) {
            throw new RuntimeException('Unable to start plugin redirect server');
        }
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if ($probe !== false) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        expect($ready)->toBeTrue();
        foreach (array('install', 'uninstall', 'enable', 'disable', 'moveup', 'movedown', 'remote_enable', 'remote_disable') as $mode) {
            foreach (array(false, true) as $ajax) {
                foreach ($mode === 'install' ? array(-1, 0) : array(-1) as $state) {
                    $data = array('mode' => $mode, 'id' => 'fixture', 'test_state' => $state);
                    if ($ajax) {
                        $data['header'] = 'false';
                    }
                    $context = stream_context_create(array('http' => array('method' => 'POST',
                        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query($data), 'follow_location' => 0, 'timeout' => 5)));
                    file_get_contents('http://' . $address . '/plugins.php', false, $context);
                    expect($http_response_header[0])->toContain('302');
                    $location = 'plugins.php' . ($state >= 0 ? '?state=5' : '');
                    if ($ajax) {
                        $location .= ($state >= 0 ? '&' : '?') . 'header=false';
                    }
                    expect($http_response_header)->toContain('Location: ' . $location);
                    expect($http_response_header)->toContain('X-Test-Mutation: ' . (str_starts_with($mode, 'remote_') ? 'remote' : $mode));
                    if ($coverage !== null) {
                        foreach (glob($dir . '/*.coverage') as $file) {
                            $coverage->merge(unserialize(file_get_contents($file)));
                            unlink($file);
                        }
                    }
                }
            }
        }
        $serverLog = file_get_contents($dir . '/server.log');
        if (preg_match('/PHP (Fatal error|Warning|Notice)/', $serverLog)) {
            throw new RuntimeException($serverLog);
        }
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        unlink($dir . '/include/auth.php');
        rmdir($dir . '/include');
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});

test('account and plugin administration reject unprotected mutation requests', function ($controller, $route, $method, $token, $expected) {
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
function db_fetch_assoc($sql) { return array(array('directory' => '2')); }
function sanitize_search_string($value) { return $value; }
function api_plugin_install($id) { echo 'WRITE'; exit; }
function api_plugin_uninstall($id) { echo 'WRITE'; exit; }
function api_plugin_enable($id) { echo 'WRITE'; exit; }
function api_plugin_disable($id) { echo 'WRITE'; exit; }
function api_plugin_moveup($id) { echo 'WRITE'; exit; }
function api_plugin_movedown($id) { echo 'WRITE'; exit; }
$config = array('poller_id' => 2);
$plugins_integrated = array();
session_id('admin-csrf-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[4];
$_REQUEST = array('id' => '2', 'user_id' => '2', 'group_id' => '2', 'type' => 'graph', 'policy_graphs' => '1', 'selected_items' => 'fixture', 'drp_action' => '1');
if ($argv[3] === 'update_policy') {
    $_REQUEST['update_policy'] = '1';
} else {
    $_REQUEST['action'] = $argv[3];
}
if ($argv[5] === 'action_array') $_REQUEST['action'] = array('save');
if ($argv[2] === 'plugins.php') {
    $_REQUEST['mode'] = $argv[5] === 'action_array' ? array($argv[3]) : $argv[3];
}
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
})->with(array(
    array('user_admin.php', 'update_policy'),
    array('user_admin.php', 'perm_remove'),
    array('user_admin.php', 'actions'),
    array('user_group_admin.php', 'update_policy'),
    array('user_group_admin.php', 'perm_remove'),
    array('user_group_admin.php', 'actions'),
    array('plugins.php', 'install'),
    array('plugins.php', 'uninstall'),
    array('plugins.php', 'enable'),
    array('plugins.php', 'disable'),
    array('plugins.php', 'moveup'),
    array('plugins.php', 'movedown'),
    array('plugins.php', 'remote_enable'),
    array('plugins.php', 'remote_disable'),
))
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
