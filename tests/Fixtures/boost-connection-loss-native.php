<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs one Boost child, boost_output_rrd_data() from poller_boost.php, on the
// real lib/database.php and lib/boost.php against a MariaDB or MySQL schema
// the test seeded from cacti.sql. RRDtool is the only replaced boundary.
//
// When RRDtool receives its first update the fixture does to the child what a
// database restart does: the server drops the child's session, and the MEMORY
// work list poller_output_boost_local_data_ids comes back empty. The child's
// outcome and every update it sent are printed as JSON.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$loss_root = dirname(__DIR__, 2);
$loss_options = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), strlen('mysql:'))), $loss_dsn);
$loss_prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
$loss_user = getenv($loss_prefix . 'USER') ?: 'root';
$loss_password = getenv($loss_prefix . 'PASSWORD') ?: '';
$loss_host = (string) $loss_dsn['host'];
$loss_port = (string) ($loss_dsn['port'] ?? '3306');
$loss_tree = (string) $loss_options['tree'];

// db_check_reconnect() reads include/config.php to open the replacement session.
file_put_contents($loss_tree . '/include/config.php', "<?php\n" .
    '$database_type = \'mysql\';' . "\n" .
    '$database_default = ' . var_export($loss_options['schema'], true) . ";\n" .
    '$database_hostname = ' . var_export($loss_host, true) . ";\n" .
    '$database_username = ' . var_export($loss_user, true) . ";\n" .
    '$database_password = ' . var_export($loss_password, true) . ";\n" .
    '$database_port = ' . var_export($loss_port, true) . ";\n" .
    '$database_retries = 2;' . "\n");
file_put_contents($loss_tree . '/lib/rrd.php', '<?php');

require $loss_root . '/include/global_constants.php';
require $loss_root . '/lib/database.php';
require $loss_root . '/tests/Helpers/PhpSource.php';
foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines', 'array_rekey', 'version_to_decimal', 'cacti_version_compare'] as $function) {
    eval(test_php_function_source(file_get_contents($loss_root . '/lib/functions.php'), $function));
}
require $loss_root . '/lib/boost.php';
foreach (['boost_output_rrd_data', 'boost_process_local_data_ids', 'boost_process_output'] as $function) {
    eval(test_php_function_source(file_get_contents($loss_root . '/poller_boost.php'), $function));
}

$logged = [];
$updates = [];
$restarted = false;

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '')
{
    $GLOBALS['logged'][] = $string;

    return true;
}

function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0) {}

function read_config_option($name, $force = false)
{
    // Two passes: 42 and 43, then 44. The session is lost while 42 is written,
    // so the next statement is the path lookup for 43 and the work list still
    // holds 44.
    return [
        'boost_rrd_update_max_records_per_select' => 4,
        'boost_rrd_update_string_length' => 2000,
        'boost_rrd_update_interval' => 60,
        'log_verbosity' => 1,
        'path_boost_log' => '',
    ][$name] ?? '';
}

function set_config_option($name, $value) {}

function get_rrdtool_version($force = false)
{
    return '1.7';
}

function get_installed_rrdtool_version()
{
    return '1.7';
}

function rrd_init($output_to_term = true, $force_storage_location_local = false, $acknowledged = false)
{
    return fopen('php://memory', 'r+');
}

function rrd_close($rrdtool_pipe)
{
    if (is_resource($rrdtool_pipe)) {
        fclose($rrdtool_pipe);
    }
}

function rrdtool_uses_proxy()
{
    return false;
}

function rrdtool_command_path($path)
{
    return "'" . $path . "'";
}

function rrdtool_ds_template_is_valid($template)
{
    return true;
}

function rrdtool_last_rejection()
{
    return null;
}

function rrdtool_rejection_is_permanent($reason)
{
    return false;
}

function poller_dead_letter_rejected($local_data_id, $rrd_path, $reason, $tables)
{
    throw new RuntimeException('A lost session is not an RRDtool rejection');
}

function rrdtool_execute($command, $output_flag, $output_type, $rrdtool_pipe = false, $logopt = 'WEBLOG')
{
    if (!$GLOBALS['restarted']) {
        $GLOBALS['restarted'] = true;
        $observer = new PDO('mysql:host=' . $GLOBALS['loss_host'] . ';port=' . $GLOBALS['loss_port'] . ';dbname=' . $GLOBALS['loss_options']['schema'], $GLOBALS['loss_user'], $GLOBALS['loss_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $observer->exec('KILL ' . (int) db_fetch_cell('SELECT CONNECTION_ID()'));
        // A restart empties every MEMORY table; InnoDB archives survive it.
        $observer->exec('DELETE FROM poller_output_boost_local_data_ids');
        usleep(200000);
    }
    $GLOBALS['updates'][] = $command;

    return true;
}

$config = ['base_path' => $loss_tree, 'library_path' => $loss_tree . '/lib', 'poller_id' => 1];
$database_hostname = $loss_host;
$database_port = $loss_port;
$database_default = (string) $loss_options['schema'];
if (db_connect_real($loss_host, $loss_user, $loss_password, $database_default, 'mysql', $loss_port, 2) === false) {
    fwrite(STDERR, 'Cannot connect to the test schema');
    exit(1);
}

$start = time();
$archive_table = (string) $loss_options['archive'];
$max_run_duration = 3600;
$result = boost_output_rrd_data(1);

print json_encode(['result' => $result, 'updates' => $updates, 'logged' => $logged], JSON_THROW_ON_ERROR);
