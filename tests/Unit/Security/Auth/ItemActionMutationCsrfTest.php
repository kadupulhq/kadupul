<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The move, delete and add links on item lists changed data from a plain GET.
 * Each controller now lists those actions in cacti_require_post_actions(), and
 * the links post from the page with the token instead.
 */

function runItemActionRequest($test, $controller, $method, $token, $action, $fields = array())
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/item-csrf-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    file_put_contents($dir . '/include/auth.php', '<?php');
    // Controllers include ./lib/ relative to the working directory.
    symlink($root . '/lib', $dir . '/lib');
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-item-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/global_constants.php';
$config = array('base_path' => $argv[1], 'include_path' => $argv[1] . '/include', 'library_path' => $argv[1] . '/lib',
    'url_path' => '/', 'poller_id' => 1, 'php_snmp_support' => false);
function __($value) { return $value; }
function __x($context, $value) { return $value; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function api_plugin_hook($name) {}
function api_plugin_hook_function($name, $value = null) { return $value; }
function get_current_page() { return 'tree.php'; }
function get_graph_group($id) { return array(1); }
function get_graph_parent($id) { return 1; }
function get_hash_data_template(...$args) { return 'hash'; }
function check_changed($request, $session) { return false; }
function sanitize_sql_column($column) { return $column; }
function top_header() { echo 'READ'; exit; }
// The first database call or sequence change shows the action handler ran.
function handler_reached() { echo 'HANDLER'; exit; }
function move_item_up(...$args) { handler_reached(); }
function move_item_down(...$args) { handler_reached(); }
function db_execute(...$args) { handler_reached(); }
function db_execute_prepared(...$args) { handler_reached(); }
function db_fetch_cell(...$args) { handler_reached(); }
function db_fetch_cell_prepared(...$args) { handler_reached(); }
function db_fetch_row(...$args) { handler_reached(); }
function db_fetch_row_prepared(...$args) { handler_reached(); }
function db_fetch_assoc(...$args) { handler_reached(); }
function db_fetch_assoc_prepared(...$args) { handler_reached(); }
session_id('item-csrf-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[3];
$_REQUEST = array_merge(array('id' => '1', 'item_id' => '2', 'order' => '2', 'rule_type' => '4', 'field_name' => 'name',
    'cdef_id' => '3', 'vdef_id' => '3', 'snmp_query_id' => '1', 'snmp_query_graph_id' => '1', 'data_template_id' => '1',
    'local_data_id' => '1', 'color_template_id' => '1', 'color_template_item_id' => '1', 'local_graph_id' => '1',
    'graph_template_id' => '1', 'host_template_id' => '1'), json_decode($argv[6], true), array('action' => $argv[5]));
$_POST = $argv[3] === 'POST' ? $_REQUEST : array();
$_GET = $argv[3] === 'GET' ? $_REQUEST : array();
if ($argv[4] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[4] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
if ($argv[4] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $argv[1] . '/' . $argv[2];
PHP;
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program,
                $root, $controller, $method, $token, $action, json_encode((object) $fields)),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the isolated item action process.');
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
        unlink($dir . '/lib');
        foreach (glob($dir . '/*.coverage') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

function itemActionCases()
{
    return array(
        array('automation_snmp.php', 'item_moveup', array()),
        array('automation_snmp.php', 'item_movedown', array()),
        array('automation_snmp.php', 'item_remove', array()),
        array('automation_templates.php', 'moveup', array()),
        array('automation_templates.php', 'movedown', array()),
        array('automation_templates.php', 'remove', array()),
        array('automation_graph_rules.php', 'item_moveup', array('rule_type' => '2')),
        array('automation_graph_rules.php', 'item_movedown', array('rule_type' => '2')),
        array('automation_graph_rules.php', 'item_remove', array('rule_type' => '2')),
        array('automation_tree_rules.php', 'item_moveup', array()),
        array('automation_tree_rules.php', 'item_movedown', array()),
        array('automation_tree_rules.php', 'item_remove', array()),
        array('cdef.php', 'item_moveup', array()),
        array('cdef.php', 'item_movedown', array()),
        array('cdef.php', 'item_remove', array()),
        array('color_templates_items.php', 'item_moveup', array()),
        array('color_templates_items.php', 'item_movedown', array()),
        array('color_templates_items.php', 'item_remove', array()),
        array('data_queries.php', 'item_moveup_gsv', array()),
        array('data_queries.php', 'item_movedown_gsv', array()),
        array('data_queries.php', 'item_remove_gsv', array()),
        array('data_queries.php', 'item_moveup_dssv', array()),
        array('data_queries.php', 'item_movedown_dssv', array()),
        array('data_queries.php', 'item_remove_dssv', array()),
        array('data_queries.php', 'item_remove', array()),
        array('data_source_profiles.php', 'item_remove', array()),
        array('data_sources.php', 'rrd_add', array()),
        array('data_sources.php', 'rrd_remove', array()),
        array('data_templates.php', 'rrd_add', array()),
        array('data_templates.php', 'rrd_remove', array()),
        array('graphs_items.php', 'item_moveup', array()),
        array('graphs_items.php', 'item_movedown', array()),
        array('graphs_items.php', 'item_remove', array()),
        array('graph_templates_items.php', 'item_moveup', array()),
        array('graph_templates_items.php', 'item_movedown', array()),
        array('graph_templates_items.php', 'item_remove', array()),
        array('host_templates.php', 'item_add_gt', array()),
        array('host_templates.php', 'item_remove_gt', array()),
        array('host_templates.php', 'item_add_dq', array()),
        array('host_templates.php', 'item_remove_dq', array()),
        array('links.php', 'move_page_up', array()),
        array('links.php', 'move_page_down', array()),
        array('links.php', 'delete_page', array()),
        array('tree.php', 'tree_up', array()),
        array('tree.php', 'tree_down', array()),
        array('vdef.php', 'item_moveup', array()),
        array('vdef.php', 'item_movedown', array()),
        array('vdef.php', 'item_remove', array()),
    );
}

test('item actions refuse a GET or an untokened POST before the handler runs', function ($controller, $action, $fields) {
    foreach (array(array('GET', 'missing', 405), array('GET', 'query', 405), array('HEAD', 'missing', 405),
        array('POST', 'missing', 403), array('POST', 'query', 403), array('POST', 'forged', 403)) as [$method, $token, $status]) {
        expect(runItemActionRequest($this, $controller, $method, $token, $action, $fields))
            ->toBe('STATUS:' . $status, "$method with a $token token");
    }
})->with(itemActionCases());

test('item actions run from a POST that carries a valid token', function ($controller, $action, $fields) {
    expect(runItemActionRequest($this, $controller, 'POST', 'valid', $action, $fields))->toBe('HANDLERSTATUS:200');
})->with(itemActionCases());

test('item editors and lists still open by GET', function ($controller, $action) {
    expect(runItemActionRequest($this, $controller, 'GET', 'missing', $action))->toBe('READSTATUS:200');
})->with(array(
    array('automation_snmp.php', 'item_edit'), array('automation_templates.php', 'edit'),
    array('automation_graph_rules.php', 'item_edit'), array('automation_tree_rules.php', 'item_edit'),
    array('cdef.php', 'item_edit'), array('color_templates_items.php', 'item_edit'),
    array('data_queries.php', 'item_edit'), array('data_source_profiles.php', 'item_edit'),
    array('data_sources.php', 'data_edit'), array('data_templates.php', 'template_edit'),
    array('graphs_items.php', 'item_edit'), array('graph_templates_items.php', 'item_edit'),
    array('host_templates.php', 'edit'), array('links.php', 'edit'), array('tree.php', 'edit'),
    array('vdef.php', 'item_edit'),
));

test('no page links an item action by GET', function () {
    $root = dirname(__DIR__, 4);
    $actions = array();
    foreach (itemActionCases() as [, $action]) {
        $actions[$action] = preg_quote($action, '/');
    }
    $pattern = '/action=(?:' . implode('|', $actions) . ')(?![a-z_])/';
    $offenders = array();
    foreach (array_merge(glob($root . '/*.php'), glob($root . '/lib/*.php')) as $path) {
        foreach (file($path) as $number => $line) {
            if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [, $offset]) {
                $before = substr($line, 0, $offset);
                // The attribute nearest the action must be data-url, never href.
                if (strrpos($before, 'href=') !== false && strrpos($before, 'href=') > (int) strrpos($before, 'data-url=')) {
                    $offenders[] = substr($path, strlen($root) + 1) . ':' . ($number + 1);
                }
            }
        }
    }

    expect($offenders)->toBe(array());
});
