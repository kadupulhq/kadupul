<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The move, delete and add links on item lists, the device template and data
 * query buttons, the tree editor, and the utility and log maintenance links
 * changed data from a plain GET. Each controller now lists those actions in
 * cacti_require_post_actions(), and the pages post them with the token instead.
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
function is_device_allowed($id) { return (int) $id === 1; }
function get_current_page() { return 'tree.php'; }
function get_graph_group($id) { return array(1); }
function get_graph_parent($id) { return 1; }
function get_hash_data_template(...$args) { return 'hash'; }
function check_changed($request, $session) { return false; }
function sanitize_sql_column($column) { return $column; }
function sanitize_search_string($value) { return $value; }
function input_validate_input_number(...$args) {}
function set_page_refresh($refresh) {}
function top_header() { echo 'READ'; exit; }
// The first database call or sequence change shows the action handler ran.
function handler_reached() { echo 'HANDLER'; exit; }
function move_item_up(...$args) { handler_reached(); }
function move_item_down(...$args) { handler_reached(); }
function db_execute(...$args) { handler_reached(); }
function db_execute_prepared(...$args) { handler_reached(); }
function db_fetch_cell(...$args) { handler_reached(); }
function db_fetch_cell_prepared(...$args) {
    if ($GLOBALS['argv'][2] === 'data_sources.php' && $GLOBALS['argv'][5] === 'data_edit') return 1;
    handler_reached();
}
function db_fetch_row(...$args) { handler_reached(); }
function db_fetch_row_prepared(...$args) { handler_reached(); }
function db_fetch_assoc(...$args) { handler_reached(); }
function db_fetch_assoc_prepared(...$args) { handler_reached(); }
function db_column_exists(...$args) { handler_reached(); }
function raise_message(...$args) { handler_reached(); }
// Legacy global includes load the helpers supplied by this isolated fixture.
function enable_device_debug($host_id) { handler_reached(); }
function disable_device_debug($host_id) { handler_reached(); }
function snmpagent_cache_rebuilt() { handler_reached(); }
function color_remove() { handler_reached(); }
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

// Inventory actions use Symfony; DeviceActionCsrfTest exercises every rendered association
// and maintenance action, CSRF rejection before mutation, and authorized writes.
// External-link actions are covered by the Symfony Link presentation tests.
// Data Input Methods, Palette and VDEF now use Symfony. Their presentation and authorization tests
// cover legacy URL rejection, GET editors, POST mutations and CSRF failures.
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
        array('tree.php', 'tree_up', array()),
        array('tree.php', 'tree_down', array()),
        array('tree.php', 'sortasc', array()),
        array('tree.php', 'sortdesc', array()),
        array('tree.php', 'copy_node', array('tree_id' => '1', 'id' => 'tbranch:2', 'parent' => 'tbranch:1', 'position' => '0')),
        array('tree.php', 'create_node', array('tree_id' => '1', 'id' => 'tbranch:1', 'position' => '0', 'text' => 'New Node')),
        array('tree.php', 'delete_node', array('tree_id' => '1', 'id' => 'tbranch:2')),
        array('tree.php', 'move_node', array('tree_id' => '1', 'id' => 'tbranch:2', 'parent' => 'tbranch:1', 'position' => '0')),
        array('tree.php', 'rename_node', array('tree_id' => '1', 'id' => 'tbranch:2', 'text' => 'Renamed')),
        array('tree.php', 'set_host_sort', array('nodeid' => 'tbranch:1', 'type' => 'hsgt')),
        array('tree.php', 'set_branch_sort', array('nodeid' => 'tbranch:1', 'type' => '1')),
        array('tree.php', 'lock', array()),
        array('tree.php', 'unlock', array()),
        array('tree.php', 'ajax_dnd', array('tree_ids' => array('line1', 'line2'))),
        array('cdef.php', 'ajax_dnd', array('cdef_item' => array('line1', 'line2'))),
        array('automation_snmp.php', 'ajax_dnd', array('snmp_item' => array('line1', 'line2'))),
        array('automation_templates.php', 'ajax_dnd', array('template_ids' => array('line1', 'line2'))),
        array('color_templates_items.php', 'ajax_dnd', array('color_item' => array('line1', 'line2'))),
        array('graphs_new.php', 'query_reload', array('host_id' => '1')),
        array('automation_graph_rules.php', 'remove', array()),
        array('automation_tree_rules.php', 'remove', array()),
        array('data_sources.php', 'ds_enable', array()),
        array('data_sources.php', 'ds_disable', array()),
        array('utilities.php', 'clear_poller_cache', array()),
        array('utilities.php', 'rebuild_resource_cache', array()),
        // utilities_clear_logfile() draws the page header before it truncates the log.
        array('utilities.php', 'clear_logfile', array(), 'READ'),
        array('utilities.php', 'purge_logfile', array('filename' => 'cacti.log-item-csrf-test')),
        array('utilities.php', 'clear_user_log', array()),
        array('utilities.php', 'purge_data_source_statistics', array()),
        array('utilities.php', 'rebuild_snmpagent_cache', array()),
    );
}

test('item actions refuse a GET or an untokened POST before the handler runs', function ($controller, $action, $fields) {
    foreach (array(array('GET', 'missing', 405), array('GET', 'query', 405), array('HEAD', 'missing', 405),
        array('POST', 'missing', 403), array('POST', 'query', 403), array('POST', 'forged', 403)) as [$method, $token, $status]) {
        expect(runItemActionRequest($this, $controller, $method, $token, $action, $fields))
            ->toBe('STATUS:' . $status, "$method with a $token token");
    }
})->with(itemActionCases());

test('item actions run from a POST that carries a valid token', function ($controller, $action, $fields, $reached = 'HANDLER') {
    expect(runItemActionRequest($this, $controller, 'POST', 'valid', $action, $fields))->toBe($reached . 'STATUS:200');
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
    array('host_templates.php', 'edit'), array('tree.php', 'edit'),
    array('utilities.php', 'view_user_log'),
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

test('pages send the device, tree, rule, data source and utility actions by POST with the token', function () {
    $root = dirname(__DIR__, 4);
    $source = function ($file) use ($root) {
        return file_get_contents($root . '/' . $file);
    };
    $token = '[^;]*__csrf_magic\'?\s*:\s*csrfMagicToken';

    expect($source('host.php'))->toContain('/config/bootstrap.php')
        ->toContain('/app.php/inventory/devices/legacy');

    expect($source('graphs_new.php'))->toMatch("/class='cactiPostAction' href='#' data-navigation='fullpage' data-url='\" \\. html_escape\\('host\\.php\\?action=query_verbose&/");

    foreach (array('copy_node', 'create_node', 'delete_node', 'move_node', 'rename_node') as $action) {
        expect($source('tree.php'))->toMatch("/\\$\\.post\\('\\?action=$action', \\{" . $token . '/');
    }
    foreach (array('set_host_sort', 'set_branch_sort') as $action) {
        expect($source('tree.php'))->toMatch("/\\$\\.post\\('tree\\.php', \\{ 'action' : '$action'" . $token . '/');
    }
    foreach (array('sortasc', 'sortdesc') as $action) {
        expect($source('tree.php'))->toMatch("/loadPage\\('tree\\.php\\?action=$action', false, true\\)/")
            ->toMatch("/'href'\\s+=> 'tree\\.php\\?action=$action',\\s+'post'\\s+=> true,/");
    }
    expect($source('tree.php'))->toMatch("/strURL = 'tree\\.php\\?action=lock&[^\\n]*\\n\\s+loadTreeEdit\\(strURL\\);/")
        ->toMatch("/strURL = 'tree\\.php\\?action=unlock&[^\\n]*\\n\\s+loadTreeEdit\\(strURL\\);/")
        ->toMatch("/function loadTreeEdit\\(url\\) \\{[^}]*\\$\\.post\\(url, \\{ __csrf_magic: csrfMagicToken \\}\\)/")
        ->toMatch("/loadPageUsingPost\\('tree\\.php\\?action=ajax_dnd', [^;]*__csrf_magic=' \\+ encodeURIComponent\\(csrfMagicToken\\)/");
    expect($source('tree.php'))->not->toMatch('/\\$\\.get\\([^)]*action=(?:\\w+_node|set_\\w+_sort|sort(?:asc|desc)|lock|unlock|ajax_dnd)/')
        ->not->toMatch('/loadPageNoHeader\\([^)]*action=(?:sort|ajax_dnd)/');

    expect($source('lib/html.php'))->toMatch('/\\$classo \\.= \' cactiPostAction\';\\s+\\$post\\s+= " data-url=\'\\$href\'";/');
    expect($source('lib/html_form.php'))->toMatch('/class=\'[^\']*cactiPostAction\' data-url=\'<\\?php print html_escape\\(\\$config\\[\'url_path\'\\] \\. \\$action_url \\. \'&confirm=true\'\\)/');
    expect($source('data_sources.php'))->toMatch('/class=\'hyperLink cactiPostAction\' href=\'#\' data-url=\'<\\?php print html_escape\\(\'data_sources\\.php\\?action=ds_\'/');
    // Migrated field deletion and whitelist confirmations use Symfony forms.
    // DataInputPresentationTest verifies missing/forged tokens before mutation.

    foreach (array('clear_poller_cache', 'rebuild_resource_cache', 'purge_data_source_statistics', 'rebuild_snmpagent_cache') as $action) {
        expect($source('utilities.php'))->toMatch("/'link'\\s+=> 'utilities\\.php\\?action=$action',\\s+'post'\\s+=> true,/");
    }
    expect($source('utilities.php'))->toMatch('/<a class=\'hyperLink cactiPostAction\' href=\'#\' data-url=\'" \\. html_escape\\(\\$details\\[\'link\'\\]\\)/');
    foreach (array('clear_user_log', 'purge_logfile') as $action) {
        expect($source('utilities.php'))->toMatch("/loadPageUsingPost\\(urlPath\\+'utilities\\.php', \\{\\s+action: '$action'" . $token . '/');
    }
    expect($source('utilities.php'))->not->toMatch('/strURL = [^;]*action=(?:clear_user_log|purge_logfile)/');
});

test('no page triggers ajax_dnd or query_reload by GET', function () {
    $root = dirname(__DIR__, 4);
    $offenders = array();
    foreach (array_merge(glob($root . '/*.php'), glob($root . '/lib/*.php'), glob($root . '/include/*.js')) as $path) {
        foreach (file($path) as $number => $line) {
            // Every request for these actions must be a POST that carries the token.
            if (preg_match('/action=(?:ajax_dnd|query_reload)(?![a-z_])/', $line) && !preg_match('/loadPageUsingPost(?:Checked)?\\(|\\$\\.post\\(/', $line)) {
                $offenders[] = substr($path, strlen($root) + 1) . ':' . ($number + 1);
            }
        }
    }

    expect($offenders)->toBe(array());

    foreach (array('cdef.php', 'automation_snmp.php', 'automation_templates.php', 'color_templates.php', 'tree.php') as $file) {
        expect(file_get_contents($root . '/' . $file))
            ->toMatch("/loadPageUsingPost(?:Checked)?\\('[a-z_]+\\.php\\?action=ajax_dnd.*?', \\$\\.tableDnD\\.serialize\\(\\) \\+ '&__csrf_magic=' \\+ encodeURIComponent\\(csrfMagicToken\\)\\);/");
    }
    expect(file_get_contents($root . '/graphs_new.php'))
        ->toMatch("/loadPageUsingPost\\('graphs_new\\.php\\?action=query_reload', \\{[^;]*__csrf_magic: csrfMagicToken/");
});
