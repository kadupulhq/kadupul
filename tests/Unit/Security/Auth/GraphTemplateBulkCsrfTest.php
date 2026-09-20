<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('graph template bulk handlers require scalar POST actions and CSRF tokens', function ($method, $shape, $token, $status, $bulk) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/graph-template-csrf-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    file_put_contents($dir . '/include/auth.php', '<?php');
    symlink($root . '/lib', $dir . '/lib');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("GRAPH_TEMPLATE_SECURITY_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $program = <<<'PHP'
$root = $argv[1];
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-graph-test-secret');
}
require $root . '/include/vendor/csrf/csrf-magic.php';
require $root . '/lib/html_utility.php';
require $root . '/include/global_constants.php';
$config = array('url_path' => '/');
function __($value) { return $value; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
// Observe entry into the real bulk handler without executing database mutations.
function sanitize_unserialize_selected_items($value) { echo 'BULK:' . get_request_var('drp_action'); exit; }
session_id('graph-template-csrf-test-session');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[2];
$field = $argv[3] === 'scalar' ? 'action' : 'action[]';
parse_str($field . '=actions&selected_items=fixture&drp_action=' . $argv[5], $_REQUEST);
$_GET = $argv[2] === 'GET' ? $_REQUEST : array();
$_POST = $argv[2] === 'POST' ? $_REQUEST : array();
if ($argv[4] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[4] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
if ($argv[4] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $root . '/graph_templates.php';
PHP;
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . $program, $root, $method, $shape, $token, $bulk), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($stderr . $stdout);
        }
        expect($stderr)->toBe('')
            ->and($stdout)->toBe(($status === 200 ? 'BULK:' . $bulk : '') . 'STATUS:' . $status);
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }
    } finally {
        unlink($dir . '/lib');
        unlink($dir . '/include/auth.php');
        rmdir($dir . '/include');
        foreach (glob($dir . '/*.coverage') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array(
    array('GET', 'scalar', 'missing', 405),
    array('GET', 'scalar', 'valid', 405),
    array('HEAD', 'scalar', 'missing', 405),
    array('POST', 'array', 'valid', 400),
    array('GET', 'array', 'missing', 400),
    array('POST', 'scalar', 'missing', 403),
    array('POST', 'scalar', 'forged', 403),
    array('POST', 'scalar', 'query', 403),
    array('POST', 'scalar', 'valid', 200),
))->with(array('1', '2', '3', '4', '5'));
