<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function runBulkMutationRequest($test, $controller, $method, $token, $action = 'actions')
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/bulk-csrf-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    file_put_contents($dir . '/include/auth.php', '<?php');
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-bulk-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/global_constants.php';
function __($value) { return $value; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function sanitize_unserialize_selected_items($value) { echo 'SELECTION'; exit; }
function top_header() { echo 'READ'; exit; }
session_id('bulk-csrf-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[3];
$_REQUEST = array('action' => json_decode($argv[5], true), 'selected_items' => 'fixture', 'drp_action' => '1');
$_POST = $argv[3] === 'POST' ? $_REQUEST : array();
$_GET = $argv[3] === 'GET' ? $_REQUEST : array();
if ($argv[4] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[4] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
if ($argv[4] === 'array') $_POST['__csrf_magic'] = array('bad');
if ($argv[4] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $argv[1] . '/' . $argv[2];
PHP;
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("BULK_CSRF_CONTROLLER",' . var_export($root . '/' . $controller, true) . ');'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root, $controller, $method, $token, json_encode($action)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the isolated bulk request process.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }
        return $stdout;
    } finally {
        unlink($dir . '/include/auth.php');
        rmdir($dir . '/include');
        foreach (glob($dir . '/*.coverage') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

// sites.php and host.php are Symfony bridges; SiteLifecycleTest,
// HostReindexExecutionTest and HTTP scenarios cover their actual boundaries.
test('bulk controllers reject unprotected confirmation requests before dispatch', function ($controller, $method, $token, $action, $status) {
    expect(runBulkMutationRequest($this, $controller, $method, $token, $action))->toBe('STATUS:' . $status);
})->with(array(
    'aggregate_graphs.php', 'aggregate_templates.php', 'automation_devices.php',
    'automation_graph_rules.php', 'automation_networks.php', 'automation_snmp.php',
    'automation_templates.php', 'automation_tree_rules.php', 'cdef.php', 'color.php',
    'color_templates.php', 'data_debug.php', 'data_input.php', 'data_queries.php',
    'data_source_profiles.php', 'data_sources.php', 'data_templates.php', 'gprint_presets.php',
    'graphs.php', 'host_templates.php', 'links.php', 'managers.php',
    'pollers.php', 'tree.php', 'user_domains.php', 'vdef.php',
))->with(array(
    array('GET', 'missing', 'actions', 405),
    array('GET', 'valid', 'actions', 405),
    array('HEAD', 'missing', 'actions', 405),
    array('PUT', 'missing', 'actions', 405),
    array('', 'missing', 'actions', 405),
    array('GET', 'missing', array('actions'), 400),
    array('POST', 'valid', array(array('actions')), 400),
    array('POST', 'missing', 'actions', 403),
    array('POST', 'query', 'actions', 403),
    array('POST', 'array', 'actions', 403),
    array('POST', 'forged', 'actions', 403),
));

test('valid bulk POST reaches existing selection validation', function () {
    expect(runBulkMutationRequest($this, 'color.php', 'POST', 'valid'))->toBe('SELECTIONSTATUS:200');
});

test('ordinary color navigation remains available without POST intent', function ($action) {
    expect(runBulkMutationRequest($this, 'color.php', 'GET', 'missing', $action))->toBe('READSTATUS:200');
})->with(array('', 'edit'));
