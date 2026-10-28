<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * After a lost session the failing statement returns false and the next one
 * runs on a new session. Boost must not read that false as a removed data
 * source, because an empty RRD path acknowledges samples without a write.
 */
function boost_lookup_probe($coverage, string $probe): array
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-lookup-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/rrd.php', '<?php');
    $script = '<?php ';
    if ($coverage !== null) {
        $script .= 'define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $script .= 'require ' . var_export($root . '/include/global_constants.php', true) . ';';
    $script .= 'require ' . var_export($root . '/lib/boost.php', true) . ';';
    $script .= <<<'STUBS'
$config = array('library_path' => __DIR__);
$writes = array();
$updates = array();
$fail = array();
function cacti_system_zone_set() {}
function cacti_log(...$args) {}
function rrd_init(...$args) { return fopen('php://temp', 'r+'); }
function rrd_close($pipe) { fclose($pipe); }
function read_config_option($key) { return 100; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return cacti_sizeof($value); }
function array_rekey($value, ...$args) { return array(); }
function get_rrdtool_version() { return '1.7'; }
function cacti_version_compare($a, $b, $operator) { return version_compare($a, $b, $operator); }
function rrdtool_uses_proxy() { return false; }
function rrdtool_command_path($path) { return "'" . $path . "'"; }
function rrdtool_ds_template_is_valid($template) { return true; }
function rrdtool_execute($command, ...$args) { $GLOBALS['updates'][] = $command; return true; }
function db_fetch_row_prepared($sql, $params = array()) {
    if (in_array('data_local', $GLOBALS['fail'], true)) {
        return false;
    }
    if (in_array('missing', $GLOBALS['fail'], true)) {
        return array();
    }
    return str_contains($sql, 'SELECT id') ? array('id' => 42) : array('data_template_id' => 1);
}
// A single-column read cannot tell a failed query from a missing row.
function db_fetch_cell_prepared($sql, $params = array()) {
    $row = db_fetch_row_prepared($sql, $params);
    return cacti_sizeof($row) ? reset($row) : false;
}
function db_fetch_assoc_prepared($sql, $params = array()) {
    if (str_contains($sql, 'rrd_path')) {
        if (in_array('ds_names', $GLOBALS['fail'], true)) {
            return false;
        }
        return in_array('missing', $GLOBALS['fail'], true) ? array() : array(array('data_source_name' => 'value', 'rrd_name' => 'value', 'rrd_path' => '/rra/42.rrd'));
    }
    return array(array('local_data_id' => 42, 'data_template_id' => 1, 'timestamp' => 1700000000, 'rrd_name' => 'value', 'output' => '7'));
}
function db_fetch_assoc($sql) { return array(array('poller_output_boost_arch_fixture')); }
function db_fetch_cell(...$args) { return 1700000060; }
function db_execute($sql) { $GLOBALS['writes'][] = $sql; return true; }
function db_execute_prepared($sql, ...$args) { return db_execute($sql); }
STUBS;
    $script .= $probe;
    try {
        file_put_contents($directory . '/probe.php', $script);
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

test('a failed RRD path lookup is reported, while a removed data source still resolves to no path', function () {
    $result = boost_lookup_probe($this->getTestResultObject()->getCodeCoverage(), <<<'PROBE'
$seen = array();
foreach (array('none' => array(), 'data_local' => array('data_local'), 'ds_names' => array('ds_names'), 'missing' => array('missing')) as $name => $failures) {
    $fail = $failures;
    $seen[$name] = boost_get_rrd_filename_and_template(42);
}
echo json_encode($seen);
PROBE);
    expect($result)->toBe(array(
        'none' => array('rrd_path' => '/rra/42.rrd', 'rrd_template' => 'value'),
        'data_local' => false,
        'ds_names' => false,
        'missing' => array('rrd_path' => '', 'rrd_template' => ''),
    ));
});

test('a missing RRD file is not acknowledged when the data source lookup fails', function () {
    $result = boost_lookup_probe($this->getTestResultObject()->getCodeCoverage(), <<<'PROBE'
$values = '1700000000:7';
$pipe = false;
$fail = array('data_local');
$failed = boost_rrdtool_function_update(42, '/nonexistent/42.rrd', 'value', $values, $pipe);
$fail = array('missing');
$removed = boost_rrdtool_function_update(42, '/nonexistent/42.rrd', 'value', $values, $pipe);
echo json_encode(array($failed, $removed, $updates));
PROBE);
    expect($result)->toBe(array('ERROR: Unable to confirm the Data Source', 'OK', array()));
});

test('on-demand Boost keeps samples queued when the RRD path lookup fails', function () {
    $result = boost_lookup_probe($this->getTestResultObject()->getCodeCoverage(), <<<'PROBE'
$fail = array('ds_names');
$result = boost_process_poller_output(42, fopen('php://temp', 'r+'));
echo json_encode(array($result, $updates, $writes));
PROBE);
    expect($result[0])->toBe(-1)->and($result[1])->toBe(array());
    foreach ($result[2] as $sql) {
        expect(preg_match('/\bDELETE\b/i', $sql))->toBe(0);
    }
});
