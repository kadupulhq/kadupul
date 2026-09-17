<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Standalone native measurement; database/configuration adapters are fixtures.
$app = realpath($argv[1] ?? '');
$outputFile = $argv[2] ?? '';
$windows = PHP_OS_FAMILY === 'Windows';
if (!$app || $outputFile === '' || (!$windows && !in_array('--unix-smoke', $argv, true))) {
    throw new RuntimeException('Usage: php windows_rrd_writer.php APPLICATION_ROOT RESULT_JSON [--unix-smoke]');
}
$binary = getenv('RRDTOOL_TEST_BINARY');
if (!$binary || !is_file($binary)) {
    throw new RuntimeException('RRDTOOL_TEST_BINARY must name the native executable');
}
function native_command(array $args)
{
    $process = proc_open($args, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start native command');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException($err);
    }
    return $out;
}
$versionOutput = native_command(array($binary, '--version'));
if (!preg_match('/RRDtool\s+([0-9.]+)/i', $versionOutput, $match)) {
    throw new RuntimeException('RRDtool did not report its version');
}
$rrdVersion = $match[1];
$directory = str_replace('\\', '/', sys_get_temp_dir()) . '/kadupul-rrd-throughput-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$config = array('cacti_server_os' => $windows ? 'win32' : 'unix', 'rra_path' => $directory, 'base_path' => $app, 'is_web' => false);
$errors = array();
function read_config_option($key)
{
    return $key === 'path_rrdtool' ? $GLOBALS['binary'] : '';
}
function get_rrdtool_version()
{
    return $GLOBALS['rrdVersion'];
}
function cacti_session_close() {}
function cacti_log($message, ...$args)
{
    if (strpos($message, 'ERROR:') !== false) {
        $GLOBALS['errors'][] = $message;
    }
}
foreach (array('CACTI_LOCALE' => 'en-US', 'CACTI_ESCAPE_CHARACTER' => $windows ? '"' : "'", 'RRDTOOL_OUTPUT_NULL' => 0, 'RRDTOOL_OUTPUT_STDOUT' => 1, 'RRDTOOL_OUTPUT_STDERR' => 2, 'RRDTOOL_OUTPUT_GRAPH_DATA' => 3, 'RRDTOOL_OUTPUT_BOOLEAN' => 4, 'RRDTOOL_OUTPUT_RETURN_STDERR' => 5, 'POLLER_VERBOSITY_NONE' => 1, 'POLLER_VERBOSITY_HIGH' => 4, 'POLLER_VERBOSITY_DEBUG' => 5) as $name => $value) {
    define($name, $value);
}
require $app . '/tests/Helpers/PhpSource.php';
$functions = file_get_contents($app . '/lib/functions.php');
foreach (array('cacti_has_control_chars', 'cacti_rrdtool_valid_path', 'cacti_rrdtool_valid_ds_name', 'cacti_rrdtool_valid_ds_template', 'cacti_escapeshellarg', 'cacti_escapeshellcmd', 'cacti_version_compare', 'version_to_decimal', 'cacti_sizeof') as $name) {
    eval(test_php_function_source($functions, $name));
}
require $app . '/lib/rrd.php';
$counts = $windows ? array(100, 1000) : array(5);
$measurements = array();
try {
    foreach ($counts as $count) {
        $updates = array();
        for ($id = 1; $id <= $count; $id++) {
            $path = $directory . '/' . $count . '-' . $id . '.rrd';
            native_command(array($binary, 'create', $path, '--start', '1700000000', '--step', '60', 'DS:value:GAUGE:120:U:U', 'RRA:AVERAGE:0.5:1:10'));
            $updates[$path] = array('local_data_id' => $id, 'data_template_id' => 0, 'times' => array(1700000060 => array('value' => $id)));
        }
        $start = microtime(true);
        $pipe = rrd_init(false, false, true);
        if ($pipe === false) {
            throw new RuntimeException('Writer initialization failed');
        }
        try {
            $written = rrdtool_function_update($updates, $pipe, $completed);
        } finally {
            rrd_close($pipe);
        }
        $elapsed = microtime(true) - $start;
        if ($written !== $count || count($completed) !== $count || $errors) {
            throw new RuntimeException('Incomplete update: ' . json_encode(array($written, $errors)));
        }
        foreach ($updates as $path => $fields) {
            if (($completed[$path][1700000060] ?? false) !== true) {
                throw new RuntimeException('Unacknowledged sample');
            }
            $last = native_command(array($binary, 'lastupdate', $path));
            if (!preg_match('/1700000060:\s+' . $fields['local_data_id'] . '(?:\.0+)?\s*$/', $last)) {
                throw new RuntimeException('Readback mismatch: ' . $last);
            }
        }
        $measurements[] = array('samples' => $count, 'files' => $count, 'seconds' => $elapsed, 'samples_per_second' => $count / max($elapsed, 0.000001), 'all_readbacks_verified' => true, 'within_300_second_budget' => $elapsed < 300);
    }
    $result = array('kind' => $windows ? 'native-windows-writer-measurement' : 'unix-smoke-only', 'platform' => php_uname(), 'php' => PHP_VERSION, 'rrdtool' => $rrdVersion, 'rrdtool_sha256' => hash_file('sha256', $binary), 'application_revision' => trim(native_command(array('git', '-C', $app, 'rev-parse', 'HEAD'))), 'application_dirty' => trim(native_command(array('git', '-C', $app, 'status', '--porcelain', '--untracked-files=all'))) !== '', 'rrd_source_sha256' => hash_file('sha256', $app . '/lib/rrd.php'), 'functions_source_sha256' => hash_file('sha256', $app . '/lib/functions.php'), 'controller_sha256' => hash_file('sha256', __FILE__), 'measurements' => $measurements, 'scope' => 'Local writer only; excludes collection, database load, proxy traffic and concurrent maintenance. Not a universal capacity guarantee.');
    file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    foreach ($measurements as $measurement) {
        if (!$measurement['within_300_second_budget']) {
            throw new RuntimeException('Writer exceeded the 300-second measurement budget');
        }
    }
} finally {
    foreach (glob($directory . '/*.rrd') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
