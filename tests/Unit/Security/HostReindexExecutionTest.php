<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('device reindex validates intent and executes an isolated argv worker', function ($method, $token, $id, $action, $failure, $expected) {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/host-reindex-test-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    mkdir($dir . '/lib', 0700);
    $files = array('include/auth.php');
    foreach (array('api_automation', 'api_data_source', 'api_device', 'api_graph', 'api_tree', 'data_query', 'html_tree', 'ping', 'poller', 'reports', 'snmp', 'template', 'utility') as $name) {
        $files[] = 'lib/' . $name . '.php';
    }
    foreach ($files as $file) {
        file_put_contents($dir . '/' . $file, '<?php');
    }
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-host-test');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/html_utility.php';
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function api_plugin_hook_function($name, $value) { return $value; }
function api_plugin_hook($name) {}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function die_html_input_error(...$args) { http_response_code(400); exit; }
function read_config_option($name) { return '/configured php/bin/php'; }
function cacti_exec($binary, $arguments, &$output, $timeout) {
    if ($binary !== '/configured php/bin/php' || $arguments !== array('-q', '/configured path/cli/poller_reindex_hosts.php', '--qid=all', '--id=7') || $timeout !== null) {
        throw new RuntimeException('Incorrect execution contract');
    }
    $GLOBALS['events'][] = 'exec';
    $output = array('<script>worker output</script>');
    return (int) $GLOBALS['failure'];
}
function db_fetch_cell_prepared($sql, $params) {
    if ($GLOBALS['failure'] || $params !== array(7)) throw new RuntimeException('Invalid result query');
    $GLOBALS['events'][] = 'count';
    return 12;
}
function raise_message($id, $message, $level) {
    if (str_contains($message, '<script>')) throw new RuntimeException('Worker output leaked');
    $GLOBALS['events'][] = $level === MESSAGE_LEVEL_ERROR ? 'error' : 'success';
}
$config = array('base_path' => '/configured path');
session_id('host-reindex-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[2];
$_REQUEST = array('action' => json_decode($argv[5], true), 'host_id' => json_decode($argv[4], true));
$_POST = $argv[2] === 'POST' ? $_REQUEST : array();
if ($argv[3] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[3] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
if ($argv[3] === 'array') $_POST['__csrf_magic'] = array('bad');
if ($argv[3] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
$failure = $argv[6];
$events = array();
register_shutdown_function(function () { echo json_encode(array(http_response_code() ?: 200, $GLOBALS['events'])); });
require $argv[1] . '/host.php';
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("HOST_REINDEX_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root,
                $method, $token, json_encode($id), json_encode($action), (string) $failure),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start host probe');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException($stderr . $stdout);
        }
        expect($stderr)->toBe('');
        expect(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
    } finally {
        foreach ($files as $file) {
            unlink($dir . '/' . $file);
        }
        foreach (glob($dir . '/*.coverage') as $report) {
            unlink($report);
        }
        rmdir($dir . '/include');
        rmdir($dir . '/lib');
        rmdir($dir);
    }
})->with(array(
    'GET' => array('GET', 'valid', '7', 'reindex', 0, array(405, array())),
    'HEAD' => array('HEAD', 'valid', '7', 'reindex', 0, array(405, array())),
    'missing token' => array('POST', 'missing', '7', 'reindex', 0, array(403, array())),
    'query token' => array('POST', 'query', '7', 'reindex', 0, array(403, array())),
    'forged token' => array('POST', 'forged', '7', 'reindex', 0, array(403, array())),
    'array token' => array('POST', 'array', '7', 'reindex', 0, array(403, array())),
    'array action' => array('POST', 'valid', '7', array('reindex'), 0, array(400, array())),
    'zero ID' => array('POST', 'valid', '0', 'reindex', 0, array(400, array())),
    'negative ID' => array('POST', 'valid', '-7', 'reindex', 0, array(400, array())),
    'missing ID' => array('POST', 'valid', null, 'reindex', 0, array(400, array())),
    'array ID' => array('POST', 'valid', array('7'), 'reindex', 0, array(400, array())),
    'shell input' => array('POST', 'valid', '7;id', 'reindex', 0, array(400, array())),
    'success' => array('POST', 'valid', '7', 'reindex', 0, array(302, array('exec', 'count', 'success'))),
    'failure' => array('POST', 'valid', '7', 'reindex', 7, array(302, array('exec', 'error'))),
));
