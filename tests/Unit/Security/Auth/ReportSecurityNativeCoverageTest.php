<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('report security helpers execute from their production files', function () {
    $root = dirname(__DIR__, 4);
    $dir  = sys_get_temp_dir() . '/report-security-' . bin2hex(random_bytes(8));

    mkdir($dir, 0700);

    $coverage = method_exists($this, 'getTestResultObject')
        ? $this->getTestResultObject()->getCodeCoverage()
        : null;
    $prelude  = '';
    if ($coverage !== null) {
        $prelude = 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'define("REPORT_SECURITY_TEST_COVERAGE",true);'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }

    $program = <<<'PHP'
$root = $argv[1];
$config = array('base_path' => $root, 'cacti_db_version' => '1.3.0');
$alignment = array();
$attach_types = array();
$reports_interval = array();
$tables = true;
$allow_report_rows = false;
$request = array('tab' => 'details');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['sess_user_id'] = 5;

function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function __esc($text, ...$args) { return __($text, ...$args); }
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function html_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function sanitize_search_string($value) { return preg_replace('/[^A-Za-z0-9_-]/', '', $value); }
function isset_request_var($name) { return array_key_exists($name, $GLOBALS['request']); }
function set_request_var($name, $value) { $GLOBALS['request'][$name] = $value; }
function get_request_var($name) { return $GLOBALS['request'][$name] ?? ''; }
function get_nfilter_request_var($name) { return get_request_var($name); }
function get_filter_request_var($name) { return (int) get_request_var($name); }
function isempty_request_var($name) { return get_request_var($name) === ''; }
function read_config_option($name) { return ''; }
function cacti_version_compare(...$args) { return false; }
function cacti_log($message, ...$args) {}
function db_table_exists($table, ...$args) { return $GLOBALS['tables']; }
function db_fetch_cell_prepared($sql, $params = array(), ...$args) {
    if ($GLOBALS['allow_report_rows']) {
        if (str_contains($sql, 'FROM reports_items')) { return 7; }
        if (str_contains($sql, 'FROM reports WHERE')) { return 1; }
    }
    if (str_contains($sql, 'FROM reports_items')) { return false; }
    if (str_contains($sql, 'FROM reports WHERE')) { return false; }
    return 1;
}
function raise_message(...$args) {}
function input_validate_input_number(...$args) {}
function db_execute_prepared(...$args) { return true; }
function db_fetch_assoc_prepared(...$args) { return array(); }
function move_item_down(...$args) {}
function move_item_up(...$args) {}
function form_input_validate($value, ...$args) { return $value; }
function is_error_message() { return true; }

require $root . '/include/global_constants.php';
require $root . '/lib/reports.php';
require $root . '/lib/auth.php';
require $root . '/lib/html_reports.php';

if (($argv[2] ?? '') === 'authorized-dnd') {
    $GLOBALS['allow_report_rows'] = true;
    $GLOBALS['request'] = array('id' => 7, 'item_id' => 70, 'report_item' => array('line70', 'line71'));
    reports_item_dnd();
    reports_item_movedown();
    reports_item_moveup();
    reports_item_remove();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    reports_require_post_action('save');
}

if (reports_sanitize_tab(array()) !== '' || reports_sanitize_tab('de!tails') !== 'details') { exit(2); }
if (reports_tab_request_var() !== 'details') { exit(3); }
reports_require_post('save');
reports_require_post_action('save');
reports_require_post_action('edit');
if (!cacti_authorize_has_realm(9001, 21)) { exit(4); }
$GLOBALS['tables'] = false;
if (!cacti_authorize_has_realm(9002, 21)) { exit(5); }
if (reports_data_query_label('<query>') !== 'Data Query: &lt;query&gt;') { exit(6); }
if (png2jpeg('') !== '' || png2gif('') !== '') { exit(7); }
if (png2jpeg('not-a-png') === '' || png2gif('not-a-png') === '') { exit(8); }
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
if (png2jpeg($png) === '' || png2gif($png) === '') { exit(9); }
$GLOBALS['request'] = array('id' => 0, 'item_id' => 70);
reports_item_dnd();
$GLOBALS['request'] = array('id' => 7, 'item_id' => 70);
reports_item_dnd();
reports_item_movedown();
reports_item_moveup();
reports_item_remove();
reports_item_edit();
$GLOBALS['request'] = array(
    'save_component_report_item' => '1',
    'report_id' => '7',
    'id' => '70',
    'sequence' => '1',
    'item_type' => (string) REPORTS_ITEM_GRAPH,
);
reports_form_save();
PHP;

    try {
        foreach (array('', 'authorized-dnd') as $scenario) {
            $process = proc_open(
                array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . $program, $root, $scenario),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
                $pipes,
                $root
            );
            expect(is_resource($process))->toBeTrue();
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0, $output);
        }

        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(2);
            foreach ($reports as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});
