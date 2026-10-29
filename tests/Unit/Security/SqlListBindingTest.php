<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security\SqlListBinding;

use RuntimeException;

/*
 * Id lists and LIKE patterns reach SQL through the shipped functions below.
 * Each case runs the function with a recording database and checks the
 * statements it issued. Upstream Cacti #7261, #7262, #7264, #7265, #8149 and
 * #8152 (GHSA-vx2m-xj58-6qv5 and the aggregate filter).
 */

$root = dirname(__DIR__, 3);

require_once $root . '/tests/Helpers/PhpSource.php';

function loadProduction(string $root, string $file, array $functions): void
{
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        throw new RuntimeException('Unreadable ' . $file);
    }
    foreach ($functions as $function) {
        // Test-only: loads this repository's own function source into a private namespace.
        eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, $function));
    }
}

loadProduction($root, 'lib/database.php', array('array_to_sql_or', 'db_like_escape'));
loadProduction($root, 'lib/functions.php', array('array_rekey'));
loadProduction($root, 'aggregate_graphs.php', array('form_actions', 'aggregate_make_sql_where'));
loadProduction($root, 'lib/api_data_source.php', array('api_data_source_remove_multi'));
loadProduction($root, 'lib/api_graph.php', array('api_delete_graphs', 'api_graph_remove_multi', 'api_graph_remove_bad_graphs'));
loadProduction($root, 'lib/reports.php', array('reports_tree_has_graphs'));

/** Records every statement; rows for a fetch come from the first matching pattern. */
function record(string $kind, string $sql, array $params = array()): array
{
    $GLOBALS['sqlLog'][] = array('kind' => $kind, 'sql' => $sql, 'params' => $params);
    foreach ($GLOBALS['sqlRows'] as $pattern => $rows) {
        if (preg_match($pattern, $sql)) {
            return $rows;
        }
    }

    return array();
}

function statements(string $pattern): array
{
    return array_values(array_filter($GLOBALS['sqlLog'], fn($entry) => preg_match($pattern, $entry['sql'])));
}

function db_qstr($value)
{
    return "'" . addslashes((string) $value) . "'";
}

function db_qstr_rlike($value)
{
    return 'RLIKE ' . db_qstr($value);
}

function db_fetch_assoc($sql)
{
    return record('fetch', $sql);
}

function db_fetch_assoc_prepared($sql, $params = array())
{
    return record('fetch', $sql, $params);
}

function db_fetch_cell_prepared($sql, $params = array())
{
    $rows = record('cell', $sql, $params);

    return $rows[0] ?? false;
}

function db_execute($sql, $log = true, $db_conn = false)
{
    record('execute', $sql);

    return true;
}

function cacti_sizeof($array)
{
    return is_array($array) ? count($array) : 0;
}

function cacti_count($array)
{
    return is_array($array) ? count($array) : 0;
}

function cacti_log() {}

function read_config_option($name, $force = false)
{
    return '';
}

function api_plugin_hook_function($hook, $data = null)
{
    return $data;
}

function get_remote_poller_ids_from_data_sources($ids)
{
    return $GLOBALS['pollerIds'];
}

function poller_push_to_remote_db_connect($poller_id, $logging = false)
{
    return 'remote' . $poller_id;
}

function api_data_source_cache_crc_update($poller_id, $function = '', $scope = null) {}

function api_graph_remove_aggregate_items($ids, $reject = false) {}

function set_config_option($name, $value) {}

function is_graph_allowed($id, $user)
{
    return true;
}

function get_filter_request_var($name, $filter = null, $options = array()) {}

function isset_request_var($name)
{
    return false;
}

function get_request_var($name, $default = '')
{
    return $_REQUEST[$name] ?? $default;
}

function get_nfilter_request_var($name, $default = '')
{
    return $_REQUEST[$name] ?? $default;
}

function input_validate_input_number($value) {}

function get_graph_title($id)
{
    return 'Graph ' . $id;
}

function add_tree_names_to_actions_array() {}

function top_header() {}

function bottom_footer() {}

function form_start($action, $id = '') {}

function form_end() {}

function html_start_box() {}

function html_end_box() {}

function escape_page_action($actions, $selected)
{
    return '';
}

function html_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function __($text, ...$args)
{
    return vsprintf($text, $args);
}

function __esc($text, ...$args)
{
    return htmlspecialchars(vsprintf($text, $args), ENT_QUOTES);
}

function html_create_list() {}

beforeEach(function () {
    $GLOBALS['sqlLog'] = $GLOBALS['sqlRows'] = array();
    $GLOBALS['pollerIds'] = array();
    $_REQUEST = $_POST = array();
});

test('the aggregate migration lists graphs and task items through bound placeholders', function () {
    $_POST = array('chk_4711' => 'on', 'chk_4712' => 'on');
    $_REQUEST = array('drp_action' => '2');
    $GLOBALS['graph_actions'] = $GLOBALS['agg_item_actions'] = array();
    $GLOBALS['sqlRows'] = array(
        '/SELECT DISTINCT task_item_id/' => array(array('task_item_id' => 8123), array('task_item_id' => 8124)),
        '/SELECT DISTINCT graph_template_id/' => array(array('graph_template_id' => 5)),
        '/FROM aggregate_graph_templates/' => array(),
    );

    ob_start();
    form_actions();
    ob_end_clean();

    $items = statements('/SELECT DISTINCT task_item_id/');
    $templates = statements('/SELECT DISTINCT graph_template_id/');

    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('fetch')
        ->and($items[0]['sql'])->not->toContain('4711')
        ->and(substr_count($items[0]['sql'], '?'))->toBe(2)
        ->and($items[0]['params'])->toBe(array('4711', '4712'))
        ->and($templates)->toHaveCount(1)
        ->and($templates[0]['sql'])->not->toContain('8123')
        ->and(substr_count($templates[0]['sql'], '?'))->toBe(2)
        ->and($templates[0]['params'])->toBe(array(8123, 8124));
});

test('the aggregate title filter quotes its value and matches LIKE wildcards literally', function (string $term, string $expected) {
    $where = aggregate_make_sql_where('', array($term), 'gtg.title_cache');

    expect($where)->toBe("WHERE (gtg.title_cache LIKE '" . $expected . "')");
})->with(array(
    'plain' => array('router', '%router%'),
    'quote' => array("a'b", "%a\\'b%"),
    'percent' => array('100%', '%100\\\\%%'),
    'underscore' => array('if_name', '%if\\\\_name%'),
    'backslash' => array('a\\b', '%a\\\\\\\\b%'),
));

test('the aggregate title filter keeps its AND, OR and grouping', function () {
    $where = aggregate_make_sql_where('WHERE x = 1', array('(a', 'b)', 'and', 'c'), 'f');

    expect($where)->toBe("WHERE x = 1 AND ((f LIKE '%a%' OR f LIKE '%b%') AND f LIKE '%c%')");
});

test('data source removal casts the data template data ids it deletes by', function (array $pollerIds) {
    $GLOBALS['pollerIds'] = $pollerIds;
    $GLOBALS['sqlRows'] = array(
        '/SELECT id\s+FROM data_template_data/' => array(array('id' => '9001) OR (1=1'), array('id' => '9002')),
    );

    api_data_source_remove_multi(array(51, 52));

    $deletes = statements('/DELETE FROM data_input_data/');

    expect($deletes)->toHaveCount(1 + count($pollerIds));
    foreach ($deletes as $delete) {
        expect($delete['sql'])->toContain('data_template_data_id IN (9001,9002)')
            ->and($delete['sql'])->not->toContain('OR');
    }
})->with(array(
    'local only' => array(array()),
    'local and remote poller' => array(array(3)),
));

test('graph removal casts the graph and data source ids in its lookups', function () {
    $GLOBALS['sqlRows'] = array(
        '/SELECT DISTINCT dtd\.local_data_id\s+FROM data_template_data AS dtd\s+INNER JOIN data_template_rrd AS dtr\s+ON dtd\.local_data_id=dtr\.local_data_id\s+INNER JOIN/' => array(array('local_data_id' => '31'), array('local_data_id' => '32')),
    );
    $ids = array('12', '13) OR (1=1');

    api_delete_graphs($ids, '2');

    $lookups = statements('/SELECT DISTINCT dtd\.local_data_id|HAVING graphs = 1/');

    expect($lookups)->toHaveCount(3)
        ->and($lookups[0]['sql'])->toContain('gti.local_graph_id IN (12,13)')
        ->and($lookups[1]['sql'])->toContain('dtd.local_data_id IN (31,32)')
        ->and($lookups[2]['sql'])->toContain('dtd.local_data_id IN (31,32)');
    foreach ($lookups as $lookup) {
        expect($lookup['sql'])->not->toContain('OR (1=1');
    }
});

test('graph removal casts every id in the delete statements', function () {
    $ids = array(12, '13); DROP TABLE graph_local;--');

    api_graph_remove_multi($ids);

    $deletes = statements('/^DELETE FROM/');

    expect($deletes)->toHaveCount(5);
    foreach ($deletes as $delete) {
        expect($delete['sql'])->toContain('IN (12, 13)')
            ->and($delete['sql'])->not->toContain('DROP');
    }
});

test('the report tree lookup binds the device, tree and branch ids', function (int $deviceId, int $branchId, int $queries) {
    $GLOBALS['config'] = array('library_path' => sys_get_temp_dir());
    file_put_contents(sys_get_temp_dir() . '/html_tree.php', "<?php\n");
    $GLOBALS['sqlRows'] = array('/SELECT host_id\s+FROM graph_tree_items/' => array($deviceId));

    reports_tree_has_graphs(4401, $branchId, 1, '');

    $lookups = statements('/SELECT gl\.id/');

    expect($lookups)->toHaveCount($queries);
    foreach ($lookups as $lookup) {
        expect($lookup['kind'])->toBe('fetch')
            ->and($lookup['sql'])->not->toMatch('/440[0-9]/')
            ->and($lookup['sql'])->not->toContain('4403')
            ->and(substr_count($lookup['sql'], '?'))->toBe(count($lookup['params']));
    }
    if ($deviceId > 0) {
        expect($lookups[0]['params'])->toBe(array($deviceId));
    } elseif ($branchId > 0) {
        expect($lookups[0]['params'])->toBe(array(4401, $branchId))
            ->and($lookups[1]['params'])->toBe(array(4401, $branchId));
    } else {
        expect($lookups[0]['params'])->toBe(array(4401))
            ->and($lookups[1]['params'])->toBe(array(4401));
    }
})->with(array(
    'device branch' => array(4403, 4402, 1),
    'tree branch' => array(0, 4402, 2),
    'tree root' => array(0, 0, 2),
));
