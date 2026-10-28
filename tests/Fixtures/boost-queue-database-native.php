<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
// Runs the real master block of poller_boost.php against a real status table.
// Queue partitions go through the real bin/legacy-boost-partition.php, which
// starts a child made from the real child block of poller_boost.php; only the
// RRD writer and the message transport are replaced. BOOST_QUEUE_MODE:
//   queue-ok        a consumer is running and handles every partition
//   queue-none      nothing consumes the queue
//   children-late   children mode while a worker of the previous run is still finishing
namespace BoostQueueDatabaseProbe;

require __DIR__ . '/boost-queue-database-bootstrap.php';
require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$root = dirname(__DIR__, 2);
$mode = getenv('BOOST_QUEUE_MODE');
$source = file_get_contents($root . '/poller_boost.php');
$clock = 0;
$log = array();
$config = array('poller_id' => 1, 'base_path' => $root);

/** A copy of the install the queue worker runs in, with a child that writes to the real table. */
function worker_install(string $root, string $source): string
{
    $dir = sys_get_temp_dir() . '/boost-queue-db-' . bin2hex(random_bytes(8));
    mkdir($dir . '/bin', 0700, true);
    mkdir($dir . '/include', 0700);
    copy($root . '/bin/legacy-boost-partition.php', $dir . '/bin/legacy-boost-partition.php');
    $bootstrap = var_export(__DIR__ . '/boost-queue-database-bootstrap.php', true);
    file_put_contents($dir . '/include/cli_check.php', '<?php require ' . $bootstrap . '; $config = array("base_path" => ' . var_export($dir, true) . ');');
    $start = strpos($source, "} else {\n    cacti_log('INFO: Boost register child process");
    $end = strpos($source, 'function sig_handler', $start);
    $child = substr(rtrim(substr($source, $start + strlen('} else {'), $end - $start - strlen('} else {'))), 0, -1);
    file_put_contents($dir . '/poller_boost.php', '<?php require ' . $bootstrap . ";\n"
        . 'function cacti_log(...$a) {} function register_process_start(...$a) { return true; } function unregister_process(...$a) {}'
        . ' function boost_output_rrd_data($child) { return 42; } function boost_log_child_statistics(...$a) {}'
        . "\n" . 'foreach (array_slice($_SERVER["argv"], 1) as $a) { [$k, $v] = explode("=", $a); if ($k === "--child") { $child = $v; } else { $run_id = $v; } }'
        . $child);

    return $dir;
}

function remove_install(string $dir): void
{
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

function run_process(array $command, string $input = ''): void
{
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

function sleep($seconds)
{
    $GLOBALS['clock'] += $seconds;

    return 0;
}

function hrtime($asNumber = false)
{
    return $GLOBALS['clock'] * 1000000000;
}

function cacti_log($message, ...$args)
{
    $GLOBALS['log'][] = $message;
}

function boost_dispatch_partitions($run, $partitions)
{
    set_config_option('last_run_id', $run);
    if (getenv('BOOST_QUEUE_MODE') !== 'queue-ok') {
        return;
    }
    $dir = worker_install($GLOBALS['root'], $GLOBALS['source']);
    try {
        for ($partition = 1; $partition <= $partitions; $partition++) {
            run_process(array(PHP_BINARY, $dir . '/bin/legacy-boost-partition.php'), json_encode(array('run' => $run, 'partition' => $partition)));
        }
    } finally {
        remove_install($dir);
    }
}

function boost_launch_children($run)
{
    $previous = read_config_option('last_run_id');
    $dir = worker_install($GLOBALS['root'], $GLOBALS['source']);
    try {
        // The worker of the earlier run had already passed its stale check.
        run_process(array(PHP_BINARY, $dir . '/poller_boost.php', '--child=1', '--run=' . $previous));
        foreach (array(1, 2) as $partition) {
            run_process(array(PHP_BINARY, $dir . '/poller_boost.php', '--child=' . $partition, '--run=' . $run));
        }
    } finally {
        remove_install($dir);
    }

    return array(1, 2);
}

function boost_wait_children(...$args)
{
    return true;
}

function cacti_sizeof($rows)
{
    return is_array($rows) ? count($rows) : 0;
}

function boost_debug(...$args) {}
function boost_time_to_run(...$args) { return true; }
function register_process_start(...$args) { return true; }
function unregister_process(...$args) {}
function boost_kill_running_processes() {}
function boost_prepare_process_table() { return true; }
function boost_prune_memstats() {}
function boost_log_statistics(...$args) {}
function boost_get_arch_table_names() { return array('poller_output_boost_arch_test'); }
function boost_archive_is_empty($name) { return true; }
function dsstats_boost_bottom() {}
function rrdcheck_boost_bottom() {}
function api_plugin_hook(...$args) {}
function boost_purge_cached_png_files(...$args) {}
function db_fetch_row(...$args) { return array('pending' => 1); }
function db_fetch_assoc(...$args) { return array(array('name' => 'poller_output_boost_arch_test')); }

register_shutdown_function(static function () {
    $rows = $GLOBALS['pdo']->query('SELECT run_id, process_handler, status FROM poller_output_boost_processes ORDER BY run_id, process_handler')->fetchAll(\PDO::FETCH_NUM);
    $settings = $GLOBALS['pdo']->query('SELECT name, value FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
    echo 'RESULT=' . json_encode(array('rows' => $rows, 'settings' => $settings, 'log' => $GLOBALS['log'])) . "\n";
});

$child = false;
$forcerun = true;
$rrd_updates = -1;
$run_failed = false;
$start = strpos($source, 'if ($child == false) {');
$end = strpos($source, "} else {\n    cacti_log('INFO: Boost register child process", $start);
$tail = test_php_function_source($source, 'boost_interval_seconds');
eval('namespace BoostQueueDatabaseProbe;' . $tail . substr($source, $start, $end - $start) . '}');
