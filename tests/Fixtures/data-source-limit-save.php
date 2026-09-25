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
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}

$config = array(
    'cacti_server_os' => 'unix', 'is_web' => true, 'poller_id' => 1, 'base_path' => $root, 'url_path' => '/',
    'library_path' => $root . '/lib', 'include_path' => $root . '/include', 'rra_path' => $root . '/rra',
    'config_options_array' => array('log_destination' => 0, 'log_verbosity' => 1),
);
$saved = array();
// The messages form_save() raises; raise_message() only needs them to exist.
$messages = array(1 => array('message' => 'Saved', 'type' => 'info'), 2 => array('message' => 'Failed', 'type' => 'error'),
    3 => array('message' => 'Validation', 'type' => 'error'), 43 => array('message' => 'Limits', 'type' => 'error'));
$no_http_headers = true;

function db_fetch_cell_prepared(...$args)
{
    return '0';
}

function db_fetch_cell(...$args)
{
    return '0';
}

function db_fetch_row_prepared(...$args)
{
    return array();
}

function db_fetch_row(...$args)
{
    return array();
}

function db_fetch_assoc_prepared(...$args)
{
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

function db_table_exists(...$args)
{
    return false;
}

function db_column_exists(...$args)
{
    return false;
}

function sql_save($row, $table, ...$args)
{
    $GLOBALS['saved'][$table] = $row;

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
    echo json_encode(array('saved' => $GLOBALS['saved'], 'errors' => array_keys($_SESSION['sess_error_fields'] ?? array())));
});
ob_start();
require $root . '/' . $page;
