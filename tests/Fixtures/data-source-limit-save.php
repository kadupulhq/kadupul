<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Child process for DataSourceLimitValidationTest: posts one save to the real
// data_sources.php or data_templates.php, with the real lib/functions.php and
// the libraries the page includes, and a database that records what is saved.
// Run from a directory holding include/auth.php (empty) and a link to lib/.
// Arguments: repository root, page, JSON request. Prints the saved rows and
// the fields that failed validation as JSON.

list(, $root, $page, $request) = $argv;
if (getenv('LIMIT_COVERAGE') === '1') {
    define('DATA_SOURCE_LIMIT_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', getcwd());
    // Keep legacy bootstrap deprecations out of this child process; the
    // application scenario below runs with every error level enabled.
    $reporting = error_reporting(error_reporting() & ~E_DEPRECATED);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
    error_reporting($reporting);
}

$config = array(
    'cacti_server_os' => 'unix', 'is_web' => true, 'poller_id' => 1, 'base_path' => $root, 'url_path' => '/',
    'library_path' => $root . '/lib', 'include_path' => $root . '/include', 'rra_path' => getenv('LIMIT_RRA_PATH') ?: $root . '/rra',
    'config_options_array' => array('log_destination' => 0, 'log_verbosity' => 1),
);
$saved = array();
$plugins_integrated = array();
$edit_hooks = array();
// The messages form_save() raises; raise_message() only needs them to exist.
$messages = array(1 => array('message' => 'Saved', 'type' => 'info'), 2 => array('message' => 'Failed', 'type' => 'error'),
    3 => array('message' => 'Validation', 'type' => 'error'), 43 => array('message' => 'Limits', 'type' => 'error'));
$no_http_headers = true;

function db_fetch_cell_prepared($sql, ...$args)
{
    // Data and item rows belong to the posted data source unless the test says otherwise.
    if (str_starts_with($sql, 'SELECT local_data_id FROM data_template_')) {
        return (str_contains($sql, 'data_template_rrd') ? getenv('LIMIT_RRD_OWNER') : getenv('LIMIT_DATA_OWNER')) ?: (getenv('LIMIT_ROW_OWNER') ?: $_REQUEST['local_data_id']);
    }

    if (str_starts_with($sql, 'SELECT host_id FROM data_local')) {
        return getenv('LIMIT_SOURCE_MISSING') === '1' ? false : (getenv('LIMIT_SOURCE_HOST') ?: '0');
    }
    if (str_starts_with($sql, 'SELECT id FROM host')) {
        return getenv('LIMIT_DEVICE_MISSING') === '1' ? false : ($args[0][0] ?? false);
    }
    return '0';
}

function is_device_allowed($device_id)
{
    return getenv('LIMIT_DEVICE_DENIED') !== '1';
}

function db_fetch_cell(...$args)
{
    return '0';
}

function db_fetch_row_prepared($sql, ...$args)
{
    if (str_starts_with($sql, 'SELECT host_id, data_template_id')) {
        return getenv('LIMIT_EDIT_MISSING') === '1' ? array() : array('host_id' => getenv('LIMIT_SOURCE_HOST') ?: '0', 'data_template_id' => '0');
    }
    return array();
}

function db_fetch_row(...$args)
{
    return array();
}

function db_fetch_assoc_prepared($sql, ...$args)
{
    if (getenv('LIMIT_EDIT_HOOK') === '1' && str_contains($sql, 'FROM plugin_hooks') && ($args[0][0] ?? '') === 'data_source_edit_top') {
        return array(array('name' => 'internal', 'file' => '', 'function' => 'limit_edit_hook'));
    }
    // A templated data source reads its items; the request names them for the test.
    if (str_contains($sql, 'FROM data_template_rrd') && isset($_REQUEST['__rrd_ids'])) {
        return array_map(function ($id) {
            return array('id' => $id);
        }, $_REQUEST['__rrd_ids']);
    }

    return array();
}

function db_fetch_assoc(...$args)
{
    return array();
}

function db_execute_prepared(...$args)
{
    return true;
}

function db_execute(...$args)
{
    return true;
}

function db_table_exists($name)
{
    return $name === 'plugin_hooks' && getenv('LIMIT_EDIT_HOOK') === '1';
}

function limit_edit_hook($args)
{
    $GLOBALS['edit_hooks'][] = $args;
    exit;
}

function db_column_exists(...$args)
{
    return false;
}

function sql_save($row, $table, ...$args)
{
    $GLOBALS['saved'][$table] = $row;
    $GLOBALS['saved_all'][$table][] = $row;

    return 5;
}

function __()
{
    $args = func_get_args();

    return count($args) > 1 ? vsprintf(array_shift($args), $args) : $args[0];
}

require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/plugins.php';
require $root . '/lib/variables.php';

$_SESSION = array('sess_user_id' => 1);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = $_POST = json_decode($request, true, 512, JSON_THROW_ON_ERROR);
register_shutdown_function(function () {
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode(array('saved' => $GLOBALS['saved'], 'hooks' => $GLOBALS['edit_hooks'], 'saved_all' => $GLOBALS['saved_all'] ?? array(), 'errors' => array_keys($_SESSION['sess_error_fields'] ?? array())));
});
ob_start();
require $root . '/' . $page;
